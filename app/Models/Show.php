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
        'type', 'channel_id',
    ];

    protected $casts = [
        'air_days' => 'array',
    ];

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