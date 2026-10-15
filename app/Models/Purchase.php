<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = ['user_id', 'show_id', 'payment_id', 'type', 'price_ugx', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function show()
    {
        return $this->belongsTo(Show::class);
    }

    /** Purchases that still grant access: bought, or a rental not yet expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('type', 'buy')->orWhere('expires_at', '>', now()));
    }
}
