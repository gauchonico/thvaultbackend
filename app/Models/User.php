<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;  // ← must be here


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;  // ← must be here

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_admin' => 'boolean'];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    // Most recent subscription still marked active — null if the user has never picked a plan.
    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function hasActivePlan(): bool
    {
        return $this->activeSubscription()->exists();
    }

}