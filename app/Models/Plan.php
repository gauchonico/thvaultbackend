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
        'is_free',
        'resolution',
        'max_screens',
        'downloads_allowed',
        'is_popular',
        'is_active',
        'features',
        'sort_order',
    ];

    protected $casts = [
        'downloads_allowed' => 'boolean',
        'is_popular' => 'boolean',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'features' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}