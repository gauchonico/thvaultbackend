<?php

namespace App\Http\Controllers\Api;

use App\Analytics\ContentAnalytics;
use App\Analytics\RevenueShare;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    // GET /api/admin/analytics?days=30
    public function index(Request $request, ContentAnalytics $analytics): JsonResponse
    {
        $days = (int) ($request->validate(['days' => 'nullable|integer|in:1,7,30,90'])['days'] ?? 30);
        // Whole days, ending today, in Kampala time.
        $to = now('Africa/Kampala')->addDay()->startOfDay();
        $from = $to->copy()->subDays($days);

        return response()->json($analytics->report($from->utc(), $to->utc()));
    }

    // GET /api/admin/revenue-share?month=2026-11[&format=csv]
    public function revenueShare(Request $request, RevenueShare $revenueShare): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'month'  => 'nullable|date_format:Y-m',
            'format' => 'nullable|in:json,csv',
        ]);
        $report = $revenueShare->forMonth($data['month'] ?? now('Africa/Kampala')->format('Y-m'));

        if (($data['format'] ?? 'json') === 'json') {
            return response()->json($report);
        }

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ["Revenue share report {$report['month']}"]);
            fputcsv($out, ['Subscription revenue (UGX)', $report['subscription_revenue_ugx']]);
            fputcsv($out, ['Subscriber watch hours', $report['subscriber_hours']]);
            fputcsv($out, []);
            fputcsv($out, ['Partner', 'Type', 'Views', 'Watch hours', 'Subscriber watch hours', 'Share of subscriber watch time (%)',
                'Subscription credit (UGX)', 'Title sales (UGX)', 'Revenue share (%)', 'Payout (UGX)']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [$row['partner'], $row['type'], $row['views'], $row['hours'], $row['subscriber_hours'],
                    $row['watch_share_percent'], $row['subscription_credit_ugx'], $row['title_sales_ugx'],
                    $row['revenue_share_percent'], $row['payout_ugx']]);
            }
            fputcsv($out, ['Total', '', '', '', '', '', '', '', '', $report['total_payout_ugx']]);
            fclose($out);
        }, "revenue-share-{$report['month']}.csv", ['Content-Type' => 'text/csv']);
    }
}
