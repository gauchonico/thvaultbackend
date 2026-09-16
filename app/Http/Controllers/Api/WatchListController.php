<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Watchlist;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function index(Request $request)
    {
        $list = Watchlist::with('show')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->pluck('show');

        return response()->json($list);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'show_id' => 'required|exists:shows,id',
        ]);

        $item = Watchlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'show_id' => $data['show_id'],
        ]);

        return response()->json($item, 201);
    }

    public function destroy(Request $request, $showId)
    {
        Watchlist::where('user_id', $request->user()->id)
            ->where('show_id', $showId)
            ->delete();

        return response()->json(['message' => 'Removed from watchlist']);
    }

    public function check(Request $request, $showId)
    {
        $exists = Watchlist::where('user_id', $request->user()->id)
            ->where('show_id', $showId)
            ->exists();

        return response()->json(['in_watchlist' => $exists]);
    }
}