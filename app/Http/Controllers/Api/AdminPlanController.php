<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin console: plans and Freemium settings. Plans are never deleted (subscriptions
 * and payments point at them); hiding one takes it off the plan picker. Price changes
 * apply to new payments only.
 */
class AdminPlanController extends Controller
{
    // GET /api/admin/plans — every plan, hidden ones included, with its numbers.
    public function index()
    {
        $subscribers = DB::table('subscriptions')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->selectRaw('plan_id, count(*) as c')->groupBy('plan_id')->pluck('c', 'plan_id');

        $revenue = Payment::where('status', 'successful')->whereNotNull('plan_id')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('plan_id, sum(amount_ugx) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        return response()->json(Plan::orderBy('sort_order')->get()->map(fn (Plan $p) => $p->toArray() + [
            'active_subscribers' => (int) ($subscribers[$p->id] ?? 0),
            'revenue_this_month' => (int) ($revenue[$p->id] ?? 0),
        ]));
    }

    // POST /api/admin/plans
    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] = (int) Plan::max('sort_order') + 1;
        $data['downloads_allowed'] = false; // not offered until downloads exist

        $plan = DB::transaction(function () use ($data) {
            $plan = Plan::create($data);
            $this->keepOnePopular($plan);
            return $plan;
        });

        $this->audit($request, 'create', $plan, $data);
        return response()->json($plan->fresh(), 201);
    }

    // PUT /api/admin/plans/{plan}
    public function update(Request $request, Plan $plan)
    {
        $data = $this->validated($request, $plan);

        // Freemium stays free: its price can't change and it can't be marked popular.
        if ($plan->is_free) {
            unset($data['price_ugx'], $data['is_popular']);
        }

        DB::transaction(function () use ($plan, $data) {
            $plan->update($data);
            $this->keepOnePopular($plan);
        });

        $this->audit($request, 'update', $plan, $data);
        return response()->json($plan->fresh());
    }

    // PUT /api/admin/plans/reorder { ids: [3, 1, 2, …] } — plan picker order.
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer|exists:plans,id']);
        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $i => $id) {
                Plan::whereKey($id)->update(['sort_order' => $i]);
            }
        });

        $this->audit($request, 'reorder', null, $data);
        return response()->json(['ok' => true]);
    }

    // GET /api/admin/settings/freemium
    public function freemium()
    {
        return response()->json([
            'preview_minutes' => intdiv(Setting::previewSeconds(), 60),
            'preview_reset'   => Setting::previewReset(),
        ]);
    }

    // PUT /api/admin/settings/freemium { preview_minutes, preview_reset }
    public function updateFreemium(Request $request)
    {
        $data = $request->validate([
            'preview_minutes' => 'required|integer|min:0|max:120',
            'preview_reset'   => 'required|in:month,day,never',
        ]);

        Setting::put('preview_seconds', $data['preview_minutes'] * 60);
        Setting::put('preview_reset', $data['preview_reset']);

        $this->audit($request, 'freemium-settings', null, $data);
        return $this->freemium();
    }

    private function validated(Request $request, ?Plan $plan): array
    {
        $data = $request->validate([
            'name'        => [$plan ? 'sometimes' : 'required', 'string', 'max:40'],
            'price_ugx'   => [$plan ? 'sometimes' : 'required', 'integer', 'min:500', 'max:10000000'],
            'resolution'  => [$plan ? 'sometimes' : 'required', 'string', 'max:40'],
            'max_screens' => [$plan ? 'sometimes' : 'required', 'integer', 'min:1', 'max:10'],
            'is_popular'  => 'sometimes|boolean',
            'is_active'   => 'sometimes|boolean',
            'features'    => 'sometimes|nullable|array|max:6',
            'features.*'  => 'nullable|string|max:60', // blank lines are dropped below
        ]);

        if (array_key_exists('features', $data)) {
            $data['features'] = array_values(array_filter(array_map(fn ($f) => trim((string) $f), $data['features'] ?? [])));
        }

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        for ($i = 2; Plan::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        return $slug;
    }

    // Only one "Most popular" plan at a time.
    private function keepOnePopular(Plan $plan): void
    {
        if ($plan->is_popular) {
            Plan::whereKeyNot($plan->id)->update(['is_popular' => false]);
        }
    }

    private function audit(Request $request, string $action, ?Plan $plan, array $data): void
    {
        Log::info("Admin plans: {$action}", [
            'admin_id' => $request->user()->id,
            'plan'     => $plan ? "Plan#{$plan->id}" : null,
            'data'     => $data,
        ]);
    }
}
