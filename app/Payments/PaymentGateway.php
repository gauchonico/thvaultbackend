<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * One implementation per payment provider. The rest of the app only talks to this,
 * so adding Pesapal (or direct MTN/Airtel later) means writing one new class.
 */
interface PaymentGateway
{
    /**
     * Ask the provider to collect $payment->amount_ugx from $payment->phone.
     *
     * Returns provider details to store on the payment:
     *  - 'provider_reference' => the provider's id for this transaction
     *  - 'redirect_url'       => set when the provider needs the user on its own
     *                            checkout page instead of a direct PIN prompt
     *
     * @return array{provider_reference: ?string, redirect_url: ?string}
     */
    public function initiate(Payment $payment): array;

    /**
     * The provider's current view of the payment.
     *
     * @return array{status: 'pending'|'successful'|'failed', reason: ?string, payload: array}
     */
    public function status(Payment $payment): array;
}
