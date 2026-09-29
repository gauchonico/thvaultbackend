<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Show;
use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'thumbnail'      => $this->thumbnailRule($request),
            'description'    => 'nullable|string',
            'video_url'      => 'nullable|string',
            'released_at'    => 'nullable|date',
        ]);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('thumbnails', 'public');
        }

        $ep = Episode::create(['show_id' => $showId, ...$data]);
        return response()->json($ep, 201);
    }

    // PUT /api/admin/episodes/{id}
    // Also reachable as POST + _method=PUT so a thumbnail file can be sent as multipart.
    public function updateEpisode(Request $request, Episode $episode)
    {
        $data = $request->validate([
            'title'          => 'string',
            'season'         => 'integer',
            'episode_number' => 'integer',
            'duration'       => 'string',
            'thumbnail'      => $this->thumbnailRule($request),
            'description'    => 'nullable|string',
            'video_url'      => 'nullable|string',
            'released_at'    => 'nullable|date',
        ]);

        $oldThumbnail = $episode->getRawOriginal('thumbnail');

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('thumbnails', 'public');
        } elseif (array_key_exists('thumbnail', $data) && $data['thumbnail'] === $episode->thumbnail) {
            // The admin form echoes back the resolved URL of an unchanged thumbnail —
            // keep the stored relative path rather than overwriting it with the full URL.
            unset($data['thumbnail']);
        }

        $episode->update($data);

        if (array_key_exists('thumbnail', $data)) {
            $this->deleteStoredThumbnail($oldThumbnail);
        }

        return response()->json($episode);
    }

    // DELETE /api/admin/episodes/{id}
    public function deleteEpisode(Episode $episode)
    {
        $thumbnail = $episode->getRawOriginal('thumbnail');
        $episode->delete();
        $this->deleteStoredThumbnail($thumbnail);
        return response()->json(['message' => 'Episode deleted']);
    }

    // A thumbnail is either an uploaded image or a URL (e.g. auto-filled from NTV VOD / YouTube).
    private function thumbnailRule(Request $request): string
    {
        return $request->hasFile('thumbnail') ? 'image|max:2048' : 'nullable|string|max:2048';
    }

    // Only files we stored ourselves are deleted — external URLs are left alone.
    private function deleteStoredThumbnail(?string $path): void
    {
        if ($path && !Str::startsWith($path, ['http://', 'https://'])) {
            Storage::disk('public')->delete($path);
        }
    }
}
