<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Simulates a mobile money collection for local testing — no money moves and no
 * network is called. Test numbers (last four digits):
 *   ...0000 → declined (like insufficient balance)
 *   ...1111 → never confirmed (lets the payment expire)
 *   anything else → succeeds a few seconds after the "prompt" is sent
 * Card payments skip the checkout page and return straight to the app, then succeed.
 */
class FakeGateway implements PaymentGateway
{
    public function initiate(Payment $payment): array
    {
        return [
            'provider_reference' => 'FAKE-' . $payment->reference,
            // A real provider returns its checkout page here; the fake goes straight back.
            'redirect_url' => $payment->method === 'card'
                ? config('payments.return_url') . '?reference=' . $payment->reference
                : null,
        ];
    }

    public function status(Payment $payment): array
    {
        $ending = substr((string) $payment->phone, -4);
        $waited = $payment->created_at->diffInSeconds(now());

        if ($ending === '1111' || $waited < config('payments.fake.confirm_after_seconds')) {
            return ['status' => 'pending', 'reason' => null, 'payload' => ['simulated' => true]];
        }

        return $ending === '0000'
            ? ['status' => 'failed', 'reason' => 'Insufficient balance (simulated).', 'payload' => ['simulated' => true]]
            : ['status' => 'successful', 'reason' => null, 'payload' => ['simulated' => true]];
    }
}
