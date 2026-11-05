<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Publication extends Model
{
    protected $fillable = ['name', 'slug', 'country', 'language', 'tagline', 'logo', 'accent_color', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function getLogoAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function editions(): HasMany
    {
        return $this->hasMany(EpaperEdition::class);
    }

    // Newest published issue — powers the newsstand cover.
    public function latestEdition(): HasOne
    {
        return $this->hasOne(EpaperEdition::class)->ofMany(
            ['issue_date' => 'max', 'id' => 'max'],
            fn ($q) => $q->where('is_published', true)
        );
    }
}
