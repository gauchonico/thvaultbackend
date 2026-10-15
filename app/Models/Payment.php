<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'plan_id', 'show_id', 'purchase_type', 'subscription_id', 'amount_ugx', 'method', 'phone', 'network',
        'provider', 'provider_reference', 'status', 'failure_reason', 'provider_payload', 'paid_at',
    ];

    protected $casts = [
        'provider_payload' => 'array',
        'paid_at'          => 'datetime',
    ];

    protected $hidden = ['provider_payload'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function show()
    {
        return $this->belongsTo(Show::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
