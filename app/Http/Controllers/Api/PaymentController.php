<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Show;
use App\Payments\PaymentService;
use App\Payments\UgandaPhone;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    // GET /api/payments/config — lets the frontend choose between the payment flow
    // and the old activate-immediately flow.
    public function config()
    {
        return response()->json([
            'enabled'  => PaymentService::enabled(),
            'provider' => PaymentService::enabled() ? config('payments.driver') : null,
            'demo'     => PaymentService::simulated(),
            'rental_hours' => config('payments.rental_hours'),
        ]);
    }

    // POST /api/payments { plan_slug | (show_id + purchase_type: rent|buy), method: mobile_money|card, phone? }
    public function store(Request $request)
    {
        if (!PaymentService::enabled()) {
            return response()->json(['message' => 'Payments are not enabled.'], 409);
        }

        $data = $request->validate([
            'plan_slug' => ['required_without:show_id', 'nullable', 'exists:plans,slug'],
            'show_id'   => ['required_without:plan_slug', 'nullable', 'exists:shows,id'],
            'purchase_type' => ['required_with:show_id', 'nullable', 'in:rent,buy'],
            'method'    => ['required', 'in:mobile_money,card'],
            'phone'     => ['required_if:method,mobile_money', 'nullable', 'string', 'max:20'],
        ]);

        $phone = $data['method'] === 'mobile_money' ? UgandaPhone::normalize($data['phone']) : null;
        if ($data['method'] === 'mobile_money' && !$phone) {
            return response()->json([
                'message' => 'Enter a valid Ugandan mobile money number.',
                'errors'  => ['phone' => ['Enter a number like 0772 123456.']],
            ], 422);
        }

        if (!empty($data['show_id'])) {
            $show = Show::findOrFail($data['show_id']);
            $price = $data['purchase_type'] === 'buy' ? $show->buy_price_ugx : $show->rent_price_ugx;
            if (!$price) {
                return response()->json(['message' => "This title isn't available to {$data['purchase_type']}."], 422);
            }
            $item = [$show, $data['purchase_type']];
        } else {
            $item = Plan::where('slug', $data['plan_slug'])->firstOrFail();
            if ($item->is_free) {
                return response()->json(['message' => 'Freemium is free: choose it without paying.'], 422);
            }
        }

        ['payment' => $payment, 'redirect_url' => $redirect] = $this->payments->start($request->user(), $item, $data['method'], $phone);

        return response()->json($this->present($payment) + ['redirect_url' => $redirect], 201);
    }

    // GET /api/payments/{reference} — polled by the "check your phone" screen.
    public function show(Request $request, string $reference)
    {
        $payment = Payment::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json($this->present($this->payments->refresh($payment)));
    }

    // GET|POST /api/payments/notify/{provider} — provider webhook (IPN). It only tells
    // us *which* payment changed; refresh() re-asks the provider for the real status,
    // so a forged notification can't mark anything paid.
    public function notify(Request $request, string $provider)
    {
        $providerReference = $request->input('OrderTrackingId') ?? $request->input('provider_reference');
        $reference = $request->input('OrderMerchantReference') ?? $request->input('reference');

        $payment = Payment::where('provider', $provider)
            ->where(fn ($q) => $q->where('reference', $reference)->orWhere('provider_reference', $providerReference))
            ->first();

        if ($payment) {
            $this->payments->refresh($payment);
        }

        return response()->json(['status' => 200, 'orderNotificationType' => $request->input('OrderNotificationType')]);
    }

    private function present(Payment $payment): array
    {
        return [
            'reference'      => $payment->reference,
            'status'         => $payment->status,
            'amount_ugx'     => $payment->amount_ugx,
            'method'         => $payment->method,
            'phone'          => $payment->phone,
            'network'        => $payment->network,
            'failure_reason' => $payment->failure_reason,
            'plan_slug'      => $payment->plan?->slug,
            'show_id'        => $payment->show_id,
            'show_title'     => $payment->show?->title,
            'purchase_type'  => $payment->purchase_type,
            'paid_at'        => $payment->paid_at?->toIso8601String(),
        ];
    }
}
