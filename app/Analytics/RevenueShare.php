<?php

namespace App\Analytics;

use App\Models\Partner;
use App\Models\Payment;
use App\Models\Play;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each partner earned in a month. Subscribers pay for The Vault as a whole,
 * so subscription revenue is divided by watch time: a partner whose content took
 * 10% of subscribers' watch time is credited 10% of the month's subscription
 * revenue, and keeps its agreed percentage of that. Rentals and purchases of a
 * partner's own titles are credited to it directly at the same percentage.
 * Advertising isn't recorded in the app yet, so it isn't included.
 */
class RevenueShare
{
    public function forMonth(string $month): array
    {
        // Calendar months in Kampala time.
        $from = Carbon::createFromFormat('Y-m-d', "{$month}-01", 'Africa/Kampala')->startOfDay();
        $to = $from->copy()->addMonth()->utc();
        $from->utc();

        $subscriptionRevenue = (int) Payment::where('status', 'successful')->whereNull('show_id')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount_ugx');

        $plays = fn () => Play::where('started_at', '>=', $from)->where('started_at', '<', $to);
        $subscriberSeconds = (int) $plays()->where('is_subscriber', true)->sum('seconds');

        $partnerPlays = $plays()->whereNotNull('partner_id')
            ->selectRaw('partner_id, COALESCE(SUM(CASE WHEN is_subscriber THEN seconds ELSE 0 END), 0) AS subscriber_seconds, '
                . 'COALESCE(SUM(seconds), 0) AS seconds, SUM(CASE WHEN seconds >= ? THEN 1 ELSE 0 END) AS views',
                [config('analytics.view_seconds')])
            ->groupBy('partner_id')->get()->keyBy('partner_id');

        $titleSales = DB::table('payments')->join('shows', 'shows.id', '=', 'payments.show_id')
            ->where('payments.status', 'successful')->whereNotNull('shows.partner_id')
            ->where('payments.paid_at', '>=', $from)->where('payments.paid_at', '<', $to)
            ->selectRaw('shows.partner_id, SUM(payments.amount_ugx) AS total')
            ->groupBy('shows.partner_id')->pluck('total', 'partner_id');

        $rows = Partner::orderBy('sort_order')->orderBy('name')->get()->map(function (Partner $partner) use (
            $partnerPlays, $titleSales, $subscriberSeconds, $subscriptionRevenue
        ) {
            $played = $partnerPlays[$partner->id] ?? null;
            $share = $subscriberSeconds ? ($played->subscriber_seconds ?? 0) / $subscriberSeconds : 0;
            $poolCredit = $subscriptionRevenue * $share;
            $sales = (int) ($titleSales[$partner->id] ?? 0);
            $percent = (float) $partner->revenue_share_percent;

            return [
                'partner_id'             => $partner->id,
                'partner'                => $partner->name,
                'type'                   => $partner->type,
                'views'                  => (int) ($played->views ?? 0),
                'hours'                  => round(($played->seconds ?? 0) / 3600, 1),
                'subscriber_hours'       => round(($played->subscriber_seconds ?? 0) / 3600, 1),
                'watch_share_percent'    => round($share * 100, 2),
                'subscription_credit_ugx' => (int) round($poolCredit),
                'title_sales_ugx'        => $sales,
                'revenue_share_percent'  => $percent,
                'payout_ugx'             => (int) round(($poolCredit + $sales) * $percent / 100),
            ];
        });

        return [
            'month'                    => $month,
            'subscription_revenue_ugx' => $subscriptionRevenue,
            'subscriber_hours'         => round($subscriberSeconds / 3600, 1),
            'rows'                     => $rows->values()->all(),
            'total_payout_ugx'         => $rows->sum('payout_ugx'),
        ];
    }
}
