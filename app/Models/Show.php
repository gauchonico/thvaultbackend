<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Show extends Model
{
    protected $fillable = [
        'title', 'description', 'poster', 'backdrop', 'trailer_url',
        'year', 'rating', 'seasons',
        'air_days', 'air_start_time', 'air_end_time',
        'type', 'channel_id', 'rent_price_ugx', 'buy_price_ugx', 'access_level',
    ];

    protected $casts = [
        'air_days' => 'array',
        'rent_price_ugx' => 'integer',
        'buy_price_ugx' => 'integer',
    ];

    public function isForSale(): bool
    {
        return (bool) ($this->rent_price_ugx || $this->buy_price_ugx);
    }

    /**
     * Decide, per show, whether $user may watch it, and strip episode video links when
     * they may not. Attaches `access` to every show:
     *
     *   has_access  — may watch in full
     *   type        — why: 'free' | 'subscription' | 'rent' | 'buy' | 'admin' | null
     *   reason      — why not: 'login' | 'subscribe' | 'purchase' | null
     *   preview     — Freemium user watching Subscribers content on their monthly
     *                 allowance (links are kept; the player stops when it runs out)
     *   preview_seconds_left, expires_at (rentals)
     *
     * Admins always get access (the admin console edits episodes through these endpoints).
     *
     * @param \Illuminate\Support\Collection<int, Show>|Show $shows
     */
    public static function applyAccess($shows, ?User $user): void
    {
        $shows = collect($shows instanceof Show ? [$shows] : $shows)->filter();
        if ($shows->isEmpty()) {
            return;
        }

        $isAdmin = (bool) $user?->is_admin;
        $isPaid = $user && !$isAdmin && $shows->contains(fn ($s) => !$s->isForSale() && $s->access_level === 'subscribers')
            ? $user->isPaidSubscriber()
            : false;
        $previewLeft = $user && !$isAdmin && !$isPaid ? PreviewUsage::secondsLeft($user) : 0;

        $forSale = $shows->filter->isForSale();
        $purchases = $user && $forSale->isNotEmpty()
            ? Purchase::active()->where('user_id', $user->id)->whereIn('show_id', $forSale->pluck('id'))
                ->orderByRaw("type = 'buy' desc")->orderByDesc('expires_at')->get()->unique('show_id')->keyBy('show_id')
            : collect();

        foreach ($shows as $show) {
            $access = ['has_access' => false, 'type' => null, 'reason' => null, 'preview' => false,
                       'preview_seconds_left' => null, 'expires_at' => null];

            if ($isAdmin) {
                $access = ['has_access' => true, 'type' => 'admin'] + $access;
            } elseif (!$user) {
                $access['reason'] = 'login';
            } elseif ($show->isForSale()) {
                $purchase = $purchases->get($show->id);
                $access['has_access'] = (bool) $purchase;
                $access['type'] = $purchase?->type;
                $access['expires_at'] = $purchase?->expires_at?->toIso8601String();
                $access['reason'] = $purchase ? null : 'purchase';
            } elseif ($show->access_level === 'subscribers') {
                $access['has_access'] = $isPaid;
                $access['type'] = $isPaid ? 'subscription' : null;
                $access['reason'] = $isPaid ? null : 'subscribe';
                $access['preview'] = !$isPaid && $previewLeft > 0;
                $access['preview_seconds_left'] = $isPaid ? null : $previewLeft;
            } else {
                $access['has_access'] = true;
                $access['type'] = 'free';
            }

            $show->setAttribute('access', $access);

            if (!$access['has_access'] && !$access['preview'] && $show->relationLoaded('episodes')) {
                $show->episodes->each(fn ($ep) => $ep->setAttribute('video_url', null));
            }
        }
    }

    /**
     * Uploaded files are stored as relative paths (e.g. "posters/xyz.jpg").
     * Seeded/demo shows store full URLs directly. Normalize both to a full URL here
     * so every consumer (API responses, admin UI, frontend) just reads $show->poster.
     */
    public function getPosterAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function getBackdropAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    /**
     * Genres (Many-to-Many)
     */
    public function genres():BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    /**
     * Managed Tags (Many-to-Many)
     * Replaces is_new and is_live flags
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('season')->orderBy('episode_number');
    }

    public function watchHistory(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}