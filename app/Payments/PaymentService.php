<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public static function enabled(): bool
    {
        $driver = config('payments.driver', 'none');

        // The fake gateway hands out subscriptions without money moving, so it only
        // runs in production when demo mode is switched on deliberately.
        if ($driver === 'fake' && app()->isProduction() && !config('payments.demo')) {
            return false;
        }

        return $driver !== 'none';
    }

    /** True when payments run on the fake gateway: the UI must say no money moves. */
    public static function simulated(): bool
    {
        return self::enabled() && config('payments.driver') === 'fake';
    }

    /**
     * Start a payment for a plan, or for a title (`$item` = [Show, 'rent'|'buy']).
     *
     * @param Plan|array{0: Show, 1: string} $item
     * @return array{payment: Payment, redirect_url: ?string}
     */
    public function start(User $user, Plan|array $item, string $method, ?string $phone): array
    {
        [$show, $purchaseType] = is_array($item) ? $item : [null, null];

        $payment = Payment::create([
            'reference'  => 'VLT-' . Str::upper(Str::random(12)),
            'user_id'    => $user->id,
            'plan_id'    => $item instanceof Plan ? $item->id : null,
            'show_id'    => $show?->id,
            'purchase_type' => $purchaseType,
            'amount_ugx' => $item instanceof Plan
                ? $item->price_ugx
                : ($purchaseType === 'buy' ? $show->buy_price_ugx : $show->rent_price_ugx),
            'method'     => $method,
            'phone'      => $phone,
            'network'    => $phone ? UgandaPhone::network($phone) : null,
            'provider'   => config('payments.driver'),
            'status'     => 'pending',
        ]);

        try {
            $result = $this->gateway->initiate($payment);
        } catch (\Throwable $e) {
            report($e);
            $payment->update(['status' => 'failed', 'failure_reason' => 'Could not reach the payment provider.']);
            return ['payment' => $payment, 'redirect_url' => null];
        }

        $payment->update(['provider_reference' => $result['provider_reference'] ?? null]);

        return ['payment' => $payment, 'redirect_url' => $result['redirect_url'] ?? null];
    }

    /**
     * Ask the provider for the latest status and apply it. Safe to call any number of
     * times (status polling and provider webhooks both land here): a payment is only
     * ever settled once.
     */
    public function refresh(Payment $payment): Payment
    {
        if (!$payment->isPending()) {
            return $payment;
        }

        try {
            $result = $this->gateway->status($payment);
        } catch (\Throwable $e) {
            report($e);
            return $payment; // try again on the next poll
        }

        return DB::transaction(function () use ($payment, $result) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (!$payment->isPending()) {
                return $payment; // settled by a concurrent poll or webhook
            }

            $payment->provider_payload = $result['payload'] ?? null;

            if ($result['status'] === 'successful') {
                $payment->fill(['status' => 'successful', 'paid_at' => now()]);
                if ($payment->show_id) {
                    $this->grantTitle($payment);
                } else {
                    $payment->subscription_id = $this->activate($payment)->id;
                }
            } elseif ($result['status'] === 'failed') {
                $payment->fill(['status' => 'failed', 'failure_reason' => $result['reason'] ?? null]);
            } elseif ($payment->created_at->diffInSeconds(now()) > config('payments.pending_timeout_seconds')) {
                $payment->fill(['status' => 'expired', 'failure_reason' => 'The payment was not confirmed in time.']);
            }

            $payment->save();
            return $payment;
        });
    }

    /** Rentals stack: renting again while a rental runs adds time from its end. */
    private function grantTitle(Payment $payment): Purchase
    {
        $expiresAt = null;
        if ($payment->purchase_type === 'rent') {
            $running = Purchase::where('user_id', $payment->user_id)->where('show_id', $payment->show_id)
                ->where('type', 'rent')->where('expires_at', '>', now())->max('expires_at');
            $expiresAt = ($running ? \Illuminate\Support\Carbon::parse($running) : now())
                ->addHours(config('payments.rental_hours'));
        }

        return Purchase::create([
            'user_id'    => $payment->user_id,
            'show_id'    => $payment->show_id,
            'payment_id' => $payment->id,
            'type'       => $payment->purchase_type,
            'price_ugx'  => $payment->amount_ugx,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Same plan still running → extend it (early renewal keeps the unused days).
     * Otherwise the new plan replaces the old one from today.
     */
    private function activate(Payment $payment)
    {
        $days = config('payments.period_days');
        $current = $payment->user->activeSubscription()->first();

        if ($current && $current->plan_id === $payment->plan_id && $current->ends_at?->isFuture()) {
            $current->update(['ends_at' => $current->ends_at->copy()->addDays($days)]);
            return $current;
        }

        $payment->user->subscriptions()->where('status', 'active')->update(['status' => 'canceled']);

        return $payment->user->subscriptions()->create([
            'plan_id'   => $payment->plan_id,
            'status'    => 'active',
            'starts_at' => now(),
            'ends_at'   => now()->addDays($days),
        ]);
    }
}
