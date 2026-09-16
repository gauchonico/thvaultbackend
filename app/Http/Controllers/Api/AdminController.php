<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Show;
use App\Models\Episode;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // GET /api/admin/users
    public function users()
    {
        return response()->json(
            User::orderByDesc('created_at')->get(['id','name','email','is_admin','created_at'])
        );
    }

    // POST /api/admin/episodes/{showId}
    public function addEpisode(Request $request, $showId)
    {
        $data = $request->validate([
            'title'          => 'required|string',
            'season'         => 'required|integer',
            'episode_number' => 'required|integer',
            'duration'       => 'required|string',
            'thumbnail'      => 'nullable|string',
            'description'    => 'nullable|string',
            'video_url'      => 'nullable|string',
            'released_at'    => 'nullable|date',
        ]);
        $ep = Episode::create(['show_id' => $showId, ...$data]);
        return response()->json($ep, 201);
    }

    // PUT /api/admin/episodes/{id}
    public function updateEpisode(Request $request, Episode $episode)
    {
        $episode->update($request->validate([
            'title'          => 'string',
            'season'         => 'integer',
            'episode_number' => 'integer',
            'duration'       => 'string',
            'thumbnail'      => 'nullable|string',
            'description'    => 'nullable|string',
            'video_url'      => 'nullable|string',
            'released_at'    => 'nullable|date',
        ]));
        return response()->json($episode);
    }

    // DELETE /api/admin/episodes/{id}
    public function deleteEpisode(Episode $episode)
    {
        $episode->delete();
        return response()->json(['message' => 'Episode deleted']);
    }
}