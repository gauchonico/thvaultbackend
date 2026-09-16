<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PromoCard extends Model
{
    protected $fillable = ['title', 'subtitle', 'image', 'genre_id', 'order'];

    public function getImageAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }
}
