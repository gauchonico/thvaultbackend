<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** A club, creator or studio with its own hub, followers and revenue share. */
class Partner extends Model
{
    protected $fillable = [
        'name', 'slug', 'type', 'sport', 'tagline', 'description', 'logo', 'banner', 'accent_color',
        'revenue_share_percent', 'contact_name', 'contact_email', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'sort_order'            => 'integer',
        'revenue_share_percent' => 'float',
    ];

    // Commercial details are for admins only.
    protected $hidden = ['revenue_share_percent', 'contact_name', 'contact_email'];

    public function getLogoAttribute(?string $value): ?string
    {
        return self::fileUrl($value);
    }

    public function getBannerAttribute(?string $value): ?string
    {
        return self::fileUrl($value);
    }

    private static function fileUrl(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'partner_follows')->withTimestamps();
    }
}
