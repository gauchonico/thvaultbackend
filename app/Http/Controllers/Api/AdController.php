<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdController extends Controller
{
    // GET /api/ads/active (public) — the pre-roll ad WatchPage plays, if any.
    // response()->json(null) would serialize to "{}" (Symfony substitutes an
    // empty ArrayObject), which is truthy in JS — return real null explicitly.
    public function active()
    {
        $ad = Ad::where('is_active', true)->first();

        return $ad
            ? response()->json($ad)
            : response('null', 200)->header('Content-Type', 'application/json');
    }

    // GET /api/admin/ads
    public function index()
    {
        return response()->json(Ad::latest()->get());
    }

    // POST /api/admin/ads
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'     => 'required|string|max:255',
            'video'     => 'required|file|mimes:mp4,mov,webm|max:51200', // 50MB
            'is_active' => 'boolean',
        ]);

        $data['video'] = $request->file('video')->store('ads', 'public');
        // FormData uploads send booleans as strings ("1"/"true") — normalize before
        // it reaches the DB, since MySQL's boolean column expects a real 0/1.
        $data['is_active'] = filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $ad = DB::transaction(function () use ($data) {
            if ($data['is_active']) {
                Ad::where('is_active', true)->update(['is_active' => false]);
            }

            return Ad::create($data);
        });

        return response()->json($ad, 201);
    }

    // PUT /api/admin/ads/{ad}
    public function update(Request $request, Ad $ad)
    {
        $data = $request->validate([
            'title'     => 'string|max:255',
            'video'     => 'nullable|file|mimes:mp4,mov,webm|max:51200',
            'is_active' => 'boolean',
        ]);

        if ($request->hasFile('video')) {
            if ($oldVideo = $ad->getRawOriginal('video')) {
                Storage::disk('public')->delete($oldVideo);
            }
            $data['video'] = $request->file('video')->store('ads', 'public');
        }
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        DB::transaction(function () use ($data, $ad) {
            if ($data['is_active'] ?? false) {
                Ad::where('is_active', true)->where('id', '!=', $ad->id)->update(['is_active' => false]);
            }

            $ad->update($data);
        });

        return response()->json($ad->fresh());
    }

    // DELETE /api/admin/ads/{ad}
    public function destroy(Ad $ad)
    {
        if ($video = $ad->getRawOriginal('video')) {
            Storage::disk('public')->delete($video);
        }

        $ad->delete();

        return response()->json(['message' => 'Ad deleted']);
    }
}
