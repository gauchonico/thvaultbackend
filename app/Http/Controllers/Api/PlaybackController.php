<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Show;
use App\Services\VdoCipher;
use Illuminate\Http\Request;

/**
 * Starts playback of a DRM-protected (VdoCipher) episode. The same access rules as
 * the show page apply: no access, no OTP. Each call returns a fresh, short-lived OTP.
 */
class PlaybackController extends Controller
{
    // POST /api/episodes/{episode}/playback
    public function store(Request $request, Episode $episode, VdoCipher $vdo)
    {
        $videoId = VdoCipher::videoId($episode->video_url);
        if (!$videoId) {
            return response()->json(['message' => 'This episode is not a protected video.'], 422);
        }
        if (!VdoCipher::configured()) {
            return response()->json(['message' => 'Protected playback is not configured on the server.'], 503);
        }

        $user = $request->user();
        $show = $episode->show;
        Show::applyAccess($show, $user);
        $access = $show->access;

        if (!$access['has_access'] && !$access['preview']) {
            return response()->json([
                'message' => 'You need access to this title to watch it.',
                'reason'  => $access['reason'],
            ], 403);
        }

        try {
            $playback = $vdo->otp($videoId, $user->email);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => "Couldn't start this video. Please try again."], 502);
        }

        return response()->json(['provider' => 'vdocipher'] + $playback);
    }
}
