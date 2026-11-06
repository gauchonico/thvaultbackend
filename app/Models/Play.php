<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One playback session: an episode watched, a live channel played or an ePaper read. */
class Play extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'user_id', 'device_id', 'content_type', 'content_id', 'show_id', 'partner_id',
        'is_subscriber', 'seconds', 'started_at', 'last_seen_at',
    ];

    protected $casts = [
        'is_subscriber' => 'boolean',
        'started_at'    => 'datetime',
        'last_seen_at'  => 'datetime',
    ];
}
