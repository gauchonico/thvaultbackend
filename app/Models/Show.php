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
        'type', 'channel_id', 'rent_price_ugx', 'buy_price_ugx',
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
     * For titles on sale, strip episode video links unless $user may watch, and attach
     * `access` so the page knows whether to show Play or Rent/Buy. Admins always see
     * the links (the admin console edits episodes through the same endpoints).
     *
     * @param \Illuminate\Support\Collection<int, Show>|Show $shows
     */
    public static function applyAccess($shows, ?User $user): void
    {
        $shows = collect($shows instanceof Show ? [$shows] : $shows)->filter->isForSale();
        if ($shows->isEmpty()) {
            return;
        }

        $purchases = $user
            ? Purchase::active()->where('user_id', $user->id)->whereIn('show_id', $shows->pluck('id'))
                ->orderByRaw("type = 'buy' desc")->orderByDesc('expires_at')->get()->unique('show_id')->keyBy('show_id')
            : collect();

        foreach ($shows as $show) {
            $purchase = $purchases->get($show->id);
            $canWatch = (bool) ($purchase || $user?->is_admin);

            $show->setAttribute('access', [
                'has_access' => $canWatch,
                'type'       => $purchase?->type ?? ($user?->is_admin ? 'admin' : null),
                'expires_at' => $purchase?->expires_at?->toIso8601String(),
            ]);

            if (!$canWatch && $show->relationLoaded('episodes')) {
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