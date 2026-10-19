<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\WatchHistory;
use App\Models\Show;
use Illuminate\Http\Request;
 
class WatchHistoryController extends Controller
{
    // GET /api/watch-history  — returns "continue watching" list
    public function index(Request $request)
    {
        $history = WatchHistory::with(['show', 'episode'])
            ->where('user_id', $request->user()->id)
            ->where('completed', false)
            ->where('progress', '>', 0)
            ->orderByDesc('watched_at')
            ->get()
            ->unique('show_id')   // one entry per show (latest episode)
            ->values();

        // A lapsed rental or plan must not keep playing from "Continue watching": hide
        // the episode link (and mark the show locked) unless the user can still watch.
        $shows = $history->pluck('show')->filter();
        \App\Models\Show::applyAccess($shows, $request->user());
        $history->each(function ($item) {
            $access = $item->show?->access;
            if ($access && !$access['has_access'] && !$access['preview'] && $item->episode) {
                $item->episode->setAttribute('video_url', null);
            }
        });

        return response()->json($history);
    }
 
    // POST /api/watch-history  — save or update progress
    public function store(Request $request)
    {
        $data = $request->validate([
            'show_id'    => 'required|exists:shows,id',
            'episode_id' => 'required|exists:episodes,id',
            'progress'   => 'required|integer|min:0|max:100',
        ]);
 
        $record = WatchHistory::updateOrCreate(
            [
                'user_id'    => $request->user()->id,
                'episode_id' => $data['episode_id'],
            ],
            [
                'show_id'    => $data['show_id'],
                'progress'   => $data['progress'],
                'completed'  => $data['progress'] >= 95,
                'watched_at' => now(),
            ]
        );
 
        return response()->json($record);
    }
 
    // DELETE /api/watch-history/{showId}  — remove a show from continue watching
    public function destroy(Request $request, $showId)
    {
        WatchHistory::where('user_id', $request->user()->id)
            ->where('show_id', $showId)
            ->delete();
 
        return response()->json(['message' => 'Removed from watch history']);
    }
}