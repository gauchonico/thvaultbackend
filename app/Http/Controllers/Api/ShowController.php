<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShowController extends Controller
{
    // GET /api/shows
    public function index(Request $request)
    {
        // Added 'genres' and 'tags' to the eager load
        $query = Show::with(['episodes', 'genres', 'tags', 'channel']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('channel_id')) {
            $query->where('channel_id', $request->channel_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Logic changed from JSON search to Relationship search
        if ($request->filled('genre')) {
            $query->whereHas('genres', function($q) use ($request) {
                $q->where('name', $request->genre);
            });
        }

        $shows = $query->latest()->get();

        // Public route, but the site sends the login token when there is one.
        Show::applyAccess($shows, auth('sanctum')->user());

        if ($request->user()) {
            $userId = $request->user()->id;
            $shows = $shows->map(function ($show) use ($userId) {
                $latest = $show->watchHistory()
                    ->where('user_id', $userId)
                    ->latest('watched_at')
                    ->first();
                $show->progress = $latest ? $latest->progress : null;
                return $show;
            });
        }

        return response()->json($shows);
    }

    // GET /api/shows/{show}
    public function show(Request $request, Show $show)
    {
        // Eager-load everything the detail page needs
        $show->load(['episodes', 'genres', 'tags', 'channel']);
        Show::applyAccess($show, auth('sanctum')->user());

        // Attach progress if logged in (matches your index() behavior)
        if ($request->user()) {
            $latest = $show->watchHistory()
                ->where('user_id', $request->user()->id)
                ->latest('watched_at')
                ->first();

            $show->progress = $latest ? $latest->progress : null;
        }

        return response()->json($show);
    }

    // POST /api/shows
    public function store(Request $request)
    {
        $this->blankPricesToNull($request);
        $data = $request->validate([
            'title'       => 'required|string',
            'description' => 'required|string',
            'poster'      => 'nullable|image|max:2048', // Changed to image upload
            'backdrop'    => 'nullable|image|max:5120', // Changed to image upload
            'trailer_url'    => 'nullable|string|max:2048',
            'year'           => 'required|integer',
            'rating'         => 'required|string',
            'seasons'        => 'integer',
            'genre_ids'      => 'required|array', // For managed genres
            'tag_ids'        => 'nullable|array',  // For managed tags
            'air_days'       => 'nullable|array',
            'air_days.*'     => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'air_start_time' => 'nullable|date_format:H:i',
            'air_end_time'   => 'nullable|date_format:H:i',
            'type'           => 'nullable|in:show,podcast',
            'channel_id'     => 'nullable|exists:channels,id',
            'rent_price_ugx' => 'nullable|integer|min:500|max:10000000',
            'buy_price_ugx'  => 'nullable|integer|min:500|max:10000000',
            'access_level'   => 'nullable|in:free,subscribers',
        ]);

        // Handle File Uploads
        if ($request->hasFile('poster')) {
            $data['poster'] = $request->file('poster')->store('posters', 'public');
        }
        if ($request->hasFile('backdrop')) {
            $data['backdrop'] = $request->file('backdrop')->store('backdrops', 'public');
        }

        $show = Show::create($data);

        // Sync Relationships
        $show->genres()->sync($request->genre_ids);
        if ($request->has('tag_ids')) {
            $show->tags()->sync($request->tag_ids);
        }

        return response()->json($show->load(['genres', 'tags', 'channel']), 201);
    }

    // PUT /api/shows/{id}
    public function update(Request $request, Show $show)
    {
        $this->blankPricesToNull($request);
        $data = $request->validate([
            'title'       => 'string',
            'description' => 'string',
            'poster'      => 'nullable|image|max:2048',
            'backdrop'    => 'nullable|image|max:5120',
            'trailer_url'    => 'nullable|string|max:2048',
            'year'           => 'integer',
            'rating'         => 'string',
            'seasons'        => 'integer',
            'genre_ids'      => 'array',
            'tag_ids'        => 'array',
            'air_days'       => 'nullable|array',
            'air_days.*'     => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'air_start_time' => 'nullable|date_format:H:i',
            'air_end_time'   => 'nullable|date_format:H:i',
            'type'           => 'nullable|in:show,podcast',
            'channel_id'     => 'nullable|exists:channels,id',
            'rent_price_ugx' => 'nullable|integer|min:500|max:10000000',
            'buy_price_ugx'  => 'nullable|integer|min:500|max:10000000',
            'access_level'   => 'nullable|in:free,subscribers',
        ]);

        $oldPoster   = $show->getRawOriginal('poster');
        $oldBackdrop = $show->getRawOriginal('backdrop');

        if ($request->hasFile('poster')) {
            if ($oldPoster) Storage::disk('public')->delete($oldPoster);
            $data['poster'] = $request->file('poster')->store('posters', 'public');
        }

        if ($request->hasFile('backdrop')) {
            if ($oldBackdrop) Storage::disk('public')->delete($oldBackdrop);
            $data['backdrop'] = $request->file('backdrop')->store('backdrops', 'public');
        }

        $show->update($data);

        if ($request->has('genre_ids')) $show->genres()->sync($request->genre_ids);
        if ($request->has('tag_ids'))   $show->tags()->sync($request->tag_ids);

        return response()->json($show->load(['genres', 'tags', 'channel']));
    }

    public function destroy(Show $show)
    {
        if ($poster = $show->getRawOriginal('poster')) Storage::disk('public')->delete($poster);
        if ($backdrop = $show->getRawOriginal('backdrop')) Storage::disk('public')->delete($backdrop);
        
        $show->delete();

        return response()->json(['message' => 'Show deleted']);
    }

    // The admin form sends an empty field to clear a price; store that as "not for sale".
    private function blankPricesToNull(Request $request): void
    {
        foreach (['rent_price_ugx', 'buy_price_ugx'] as $field) {
            if ($request->has($field) && trim((string) $request->input($field)) === '') {
                $request->merge([$field => null]);
            }
        }
    }
}
