<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\EpaperEdition;
use App\Models\Episode;
use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlayController extends Controller
{
    // POST /api/plays — heartbeat from a player while it plays. Public, so signed-out
    // viewers count too; the login token is used when the site sends one.
    public function heartbeat(Request $request): Response
    {
        $data = $request->validate([
            'session_id'   => 'required|uuid',
            'device_id'    => 'required|string|max:64',
            'content_type' => 'required|in:episode,channel,epaper',
            'content_id'   => 'required|integer|min:1',
            'seconds'      => 'required|integer|min:0',
        ]);

        $now = now();
        $play = Play::firstWhere('session_id', $data['session_id']);

        if (!$play) {
            [$showId, $partnerId] = $this->resolve($data['content_type'], $data['content_id']);
            $user = auth('sanctum')->user();

            Play::create([
                'session_id'    => $data['session_id'],
                'user_id'       => $user?->id,
                'device_id'     => $data['device_id'],
                'content_type'  => $data['content_type'],
                'content_id'    => $data['content_id'],
                'show_id'       => $showId,
                'partner_id'    => $partnerId,
                'is_subscriber' => (bool) $user?->isPaidSubscriber(),
                'seconds'       => min($data['seconds'], config('analytics.heartbeat_slack_seconds') * 2),
                'started_at'    => $now,
                'last_seen_at'  => $now,
            ]);

            return response()->noContent();
        }

        // A session belongs to one device and one piece of content.
        if ($play->device_id !== $data['device_id'] || $play->content_type !== $data['content_type']
            || $play->content_id !== $data['content_id']) {
            return response()->noContent(409);
        }

        // Grow by no more than the real time since the last heartbeat.
        $elapsed = $play->last_seen_at->diffInSeconds($now, true) + config('analytics.heartbeat_slack_seconds');
        $seconds = min($data['seconds'], $play->seconds + (int) $elapsed, config('analytics.max_session_seconds'));

        $play->update(['seconds' => max($play->seconds, $seconds), 'last_seen_at' => $now]);

        return response()->noContent();
    }

    /** @return array{0: ?int, 1: ?int} show_id and partner_id, or 404 if the content doesn't exist */
    private function resolve(string $type, int $id): array
    {
        if ($type === 'episode') {
            $episode = Episode::with('show:id,partner_id')->findOrFail($id);
            return [$episode->show_id, $episode->show?->partner_id];
        }

        ($type === 'channel' ? Channel::class : EpaperEdition::class)::findOrFail($id);

        return [null, null];
    }
}
