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
        $user = $request->user();
        $subscription = $user->activeSubscription()->with('plan')->first();

        // Someone who has chosen a plan before never gets bounced back to the plan
        // picker: when a paid plan lapses they drop to Freemium automatically.
        if (!$subscription && $user->subscriptions()->exists() && ($free = Plan::where('is_free', true)->first())) {
            $subscription = $user->subscriptions()->create([
                'plan_id' => $free->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => null,
            ])->load('plan');
        }

        $isFree = (bool) $subscription?->plan?->is_free;

        return response()->json([
            'has_active_plan'      => (bool) $subscription,
            'tier'                 => $subscription ? ($isFree ? 'free' : 'paid') : null,
            'subscription'         => $subscription,
            'preview_seconds_left' => $isFree ? \App\Models\PreviewUsage::secondsLeft($user) : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'exists:plans,slug'],
        ]);

        $plan = Plan::where('slug', $data['plan_slug'])->firstOrFail();
        if (!$plan->is_active) {
            return response()->json(['message' => "That plan isn't available any more. Please choose another."], 422);
        }

        // Once payments are on, a paid plan is only activated by a confirmed payment.
        // Freemium never needs one.
        if (\App\Payments\PaymentService::enabled() && !$plan->is_free) {
            return response()->json(['message' => 'Choose a plan and pay with mobile money to subscribe.'], 409);
        }
        $user = $request->user();

        $subscription = DB::transaction(function () use ($user, $plan) {
            // Supersede whatever was active before — a user only has one live plan at a time.
            $user->subscriptions()->where('status', 'active')->update(['status' => 'canceled']);

            return $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $plan->is_free ? null : now()->addMonth(), // Freemium doesn't expire
            ]);
        });

        return response()->json($subscription->load('plan'), 201);
    }
}