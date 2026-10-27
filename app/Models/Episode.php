<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Episode extends Model
{
    protected $fillable = [
        'show_id', 'title', 'season', 'episode_number',
        'duration', 'thumbnail', 'description', 'video_url', 'released_at', 'aired_at',
    ];

    protected $casts = [
        'released_at' => 'date:Y-m-d',
        'aired_at'    => 'date:Y-m-d',
    ];

    /**
     * Uploaded thumbnails are stored as relative paths (e.g. "thumbnails/xyz.jpg"),
     * while ones pulled from NTV VOD / YouTube are full URLs. Normalize both.
     */
    public function getThumbnailAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    // A pasted VdoCipher video ID is stored as "vdocipher:<id>" so every client can
    // tell which player it needs.
    public function setVideoUrlAttribute(?string $value): void
    {
        $vdoId = \App\Services\VdoCipher::videoId($value);
        $this->attributes['video_url'] = $vdoId ? "vdocipher:{$vdoId}" : $value;
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}