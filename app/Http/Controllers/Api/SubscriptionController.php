<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    // Frontend calls this right after login to decide: send the user into
    // the app, or back to /select-plan.
    public function current(Request $request)
    {
        $subscription = $request->user()->activeSubscription()->with('plan')->first();

        return response()->json([
            'has_active_plan' => (bool) $subscription,
            'subscription' => $subscription,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'exists:plans,slug'],
        ]);

        $plan = Plan::where('slug', $data['plan_slug'])->firstOrFail();
        $user = $request->user();

        $subscription = DB::transaction(function () use ($user, $plan) {
            // Supersede whatever was active before — a user only has one live plan at a time.
            $user->subscriptions()->where('status', 'active')->update(['status' => 'canceled']);

            return $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => 'active', // swap to 'pending' once a payment gateway sits in front of this
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
        });

        return response()->json($subscription->load('plan'), 201);
    }
}