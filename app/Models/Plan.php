<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price_ugx',
        'resolution',
        'max_screens',
        'downloads_allowed',
        'is_popular',
        'sort_order',
    ];

    protected $casts = [
        'downloads_allowed' => 'boolean',
        'is_popular' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}