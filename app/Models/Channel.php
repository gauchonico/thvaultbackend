<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Channel extends Model
{
    protected $fillable = ['name', 'slug', 'logo', 'stream_url', 'is_live'];

    protected $casts = [
        'is_live' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($channel) {
            $channel->slug = $channel->slug ?? Str::slug($channel->name);
        });
    }

    public function getLogoAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }
}
