<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PreviewUsage;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts Freemium users' preview time on Subscribers content. The player calls this
 * every ~10 seconds while playing. The server measures the time between calls itself
 * (it never trusts a number from the client), so the allowance can't be inflated.
 */
class PreviewController extends Controller
{
    // A gap longer than this means the video was paused or the tab slept: start a new
    // run instead of charging for the gap.
    private const MAX_GAP_SECONDS = 30;
    // At most this much is charged per call (calls are ~10s apart).
    private const MAX_CHARGE_SECONDS = 12;

    // POST /api/preview/heartbeat { show_id }
    public function heartbeat(Request $request)
    {
        $data = $request->validate(['show_id' => 'required|exists:shows,id']);
        $user = $request->user();
        $show = Show::findOrFail($data['show_id']);

        // Only Freemium watching Subscribers content uses the allowance.
        if ($user->is_admin || $show->isForSale() || $show->access_level !== 'subscribers' || $user->isPaidSubscriber()) {
            return response()->json(['counting' => false, 'seconds_left' => null]);
        }

        $left = DB::transaction(function () use ($user) {
            $usage = PreviewUsage::firstOrCreate(['user_id' => $user->id, 'period' => PreviewUsage::currentPeriod()]);
            $usage = PreviewUsage::whereKey($usage->id)->lockForUpdate()->first();

            $gap = $usage->last_beat_at ? $usage->last_beat_at->diffInSeconds(now()) : null;
            $charge = $gap !== null && $gap <= self::MAX_GAP_SECONDS ? (int) min($gap, self::MAX_CHARGE_SECONDS) : 0;

            $usage->seconds_used = min(config('content.preview_seconds'), $usage->seconds_used + $charge);
            $usage->last_beat_at = now();
            $usage->save();

            return max(0, config('content.preview_seconds') - $usage->seconds_used);
        });

        return response()->json(['counting' => true, 'seconds_left' => $left]);
    }
}
