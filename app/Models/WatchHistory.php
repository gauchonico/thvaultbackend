<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchHistory extends Model
{
    protected $table = 'watch_history'; // ← tells Laravel exact table name

    protected $fillable = [
        'user_id', 'show_id', 'episode_id', 'progress', 'completed', 'watched_at',
    ];

    protected $casts = [
        'completed'  => 'boolean',
        'watched_at' => 'datetime',
    ];

    public function show(): BelongsTo    { return $this->belongsTo(Show::class); }
    public function episode(): BelongsTo { return $this->belongsTo(Episode::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
}