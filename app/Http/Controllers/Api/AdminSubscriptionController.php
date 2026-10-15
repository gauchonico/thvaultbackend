<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Show;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin console: subscribers and payments. Every change is logged with the admin's
 * id so support actions (extensions, grants) can be traced later.
 */
class AdminSubscriptionController extends Controller
{
    private const EXPIRING_DAYS = 7;

    // GET /api/admin/subscriptions/summary
    public function summary()
    {
        return response()->json([
            'active'             => $this->live(Subscription::query())->count(),
            'expiring_soon'      => $this->live(Subscription::query())
                ->whereBetween('ends_at', [now(), now()->addDays(self::EXPIRING_DAYS)])->count(),
            'revenue_this_month' => (int) Payment::where('status', 'successful')
                ->where('paid_at', '>=', now()->startOfMonth())->sum('amount_ugx'),
            'payments_this_month' => Payment::where('status', 'successful')
                ->where('paid_at', '>=', now()->startOfMonth())->count(),
            'failed_last_24h'    => Payment::whereIn('status', ['failed', 'expired'])
                ->where('created_at', '>=', now()->subDay())->count(),
            'pending'            => Payment::where('status', 'pending')->count(),
            'payments_enabled'   => PaymentService::enabled(),
            'payments_demo'      => PaymentService::simulated(),
        ]);
    }

    // GET /api/admin/subscriptions?filter=active|expiring|expired|canceled|all&search=
    public function index(Request $request)
    {
        $filter = $request->query('filter', 'active');
        $search = trim((string) $request->query('search', ''));

        $query = Subscription::with(['user:id,name,email', 'plan:id,name,slug,price_ugx'])
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderByDesc('updated_at');

        match ($filter) {
            'active'   => $this->live($query),
            'expiring' => $this->live($query)->whereBetween('ends_at', [now(), now()->addDays(self::EXPIRING_DAYS)]),
            'expired'  => $query->where(fn ($q) => $q->where('status', 'expired')
                ->orWhere(fn ($q) => $q->where('status', 'active')->where('ends_at', '<=', now()))),
            'canceled' => $query->where('status', 'canceled'),
            default    => $query,
        };

        $page = $query->paginate(25);
        $page->getCollection()->transform(fn (Subscription $s) => $this->present($s));

        return response()->json($page);
    }

    // POST /api/admin/subscriptions/{subscription}/extend { days }
    public function extend(Request $request, Subscription $subscription)
    {
        $data = $request->validate(['days' => 'required|integer|min:1|max:366']);

        // Extending a lapsed plan restarts it from today rather than from the past.
        $from = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at : now();
        $subscription->update(['status' => 'active', 'ends_at' => $from->copy()->addDays($data['days'])]);

        $this->audit($request, 'extend', $subscription, $data);
        return response()->json($this->present($subscription->fresh(['user', 'plan'])));
    }

    // PUT /api/admin/subscriptions/{subscription} { plan_slug?, status? }
    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'plan_slug' => 'nullable|exists:plans,slug',
            'status'    => 'nullable|in:active,canceled',
        ]);

        if (!empty($data['plan_slug'])) {
            $subscription->plan_id = Plan::where('slug', $data['plan_slug'])->value('id');
        }
        if (!empty($data['status'])) {
            $subscription->status = $data['status'];
        }
        $subscription->save();

        $this->audit($request, 'update', $subscription, $data);
        return response()->json($this->present($subscription->fresh(['user', 'plan'])));
    }

    // POST /api/admin/subscriptions/grant { email, plan_slug, days }
    public function grant(Request $request)
    {
        $data = $request->validate([
            'email'     => 'required|email|exists:users,email',
            'plan_slug' => 'required|exists:plans,slug',
            'days'      => 'required|integer|min:1|max:366',
        ], ['email.exists' => 'No account uses that email.']);

        $user = User::where('email', $data['email'])->firstOrFail();
        $plan = Plan::where('slug', $data['plan_slug'])->firstOrFail();

        $subscription = DB::transaction(function () use ($user, $plan, $data) {
            $user->subscriptions()->where('status', 'active')->update(['status' => 'canceled']);
            return $user->subscriptions()->create([
                'plan_id'   => $plan->id,
                'status'    => 'active',
                'starts_at' => now(),
                'ends_at'   => now()->addDays($data['days']),
            ]);
        });

        $this->audit($request, 'grant', $subscription, $data);
        return response()->json($this->present($subscription->load(['user', 'plan'])), 201);
    }

    // GET /api/admin/payments?status=&search=
    public function payments(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $phoneDigits = preg_replace('/\D/', '', $search);

        $page = Payment::with(['user:id,name,email', 'plan:id,name,slug', 'show:id,title'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('provider_reference', 'like', "%{$search}%")
                ->when(strlen($phoneDigits) >= 6, fn ($q) => $q->orWhere('phone', 'like', '%' . ltrim($phoneDigits, '0') . '%'))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->latest()
            ->paginate(25);

        return response()->json($page);
    }

    // POST /api/admin/payments/{payment}/refresh — re-ask the provider (stuck payments).
    public function refreshPayment(Request $request, Payment $payment, PaymentService $payments)
    {
        $payment = $payments->refresh($payment);
        $this->audit($request, 'refresh-payment', $payment, []);

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name,slug', 'show:id,title']));
    }

    // GET /api/admin/sales — rent/buy titles with what each has earned.
    public function sales()
    {
        $stats = Purchase::selectRaw("show_id,
                SUM(type = 'rent') as rentals, SUM(type = 'buy') as purchases,
                SUM(price_ugx) as revenue_ugx,
                SUM(type = 'rent' AND expires_at > ?) as active_rentals,
                MAX(created_at) as last_sale_at", [now()])
            ->groupBy('show_id')->get()->keyBy('show_id');

        $titles = Show::where(fn ($q) => $q->whereNotNull('rent_price_ugx')->orWhereNotNull('buy_price_ugx'))
            ->orWhereIn('id', $stats->keys())
            ->get(['id', 'title', 'poster', 'rent_price_ugx', 'buy_price_ugx'])
            ->map(function (Show $show) use ($stats) {
                $s = $stats->get($show->id);
                return [
                    'id'             => $show->id,
                    'title'          => $show->title,
                    'poster'         => $show->poster,
                    'rent_price_ugx' => $show->rent_price_ugx,
                    'buy_price_ugx'  => $show->buy_price_ugx,
                    'rentals'        => (int) ($s->rentals ?? 0),
                    'purchases'      => (int) ($s->purchases ?? 0),
                    'active_rentals' => (int) ($s->active_rentals ?? 0),
                    'revenue_ugx'    => (int) ($s->revenue_ugx ?? 0),
                    'last_sale_at'   => $s?->last_sale_at,
                ];
            })
            ->sortByDesc('revenue_ugx')->values();

        return response()->json([
            'titles'      => $titles,
            'total_ugx'   => $titles->sum('revenue_ugx'),
            'total_sales' => $titles->sum(fn ($t) => $t['rentals'] + $t['purchases']),
        ]);
    }

    /** Active and not past its end date. */
    private function live(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    private function present(Subscription $s): array
    {
        $lapsed = $s->status === 'active' && $s->ends_at && $s->ends_at->isPast();

        return [
            'id'        => $s->id,
            'user'      => $s->user?->only(['id', 'name', 'email']),
            'plan'      => $s->plan?->only(['id', 'name', 'slug', 'price_ugx']),
            'status'    => $lapsed ? 'expired' : $s->status,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at'   => $s->ends_at?->toIso8601String(),
            'last_payment' => Payment::where('subscription_id', $s->id)->where('status', 'successful')
                ->latest('paid_at')->first(['reference', 'method', 'amount_ugx', 'paid_at']),
        ];
    }

    private function audit(Request $request, string $action, $model, array $data): void
    {
        Log::info("Admin subscriptions: {$action}", [
            'admin_id' => $request->user()->id,
            'model'    => class_basename($model) . '#' . $model->id,
            'data'     => $data,
        ]);
    }
}
