<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
class Episode extends Model
{
    protected $fillable = [
        'show_id', 'title', 'season', 'episode_number',
        'duration', 'thumbnail', 'description', 'video_url', 'released_at',
    ];

    protected $casts = [
        'released_at' => 'date:Y-m-d',
    ];
 
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}