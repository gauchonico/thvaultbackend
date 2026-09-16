<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Watchlist extends Model
{
    protected $table = 'watchlist'; // ← tells Laravel exact table name

    protected $fillable = ['user_id', 'show_id'];

    public function show(): BelongsTo { return $this->belongsTo(Show::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}