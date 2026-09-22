<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReleaseNote;
use Illuminate\Http\Request;

class ReleaseNoteController extends Controller
{
    // GET /api/release-notes/latest (public) — powers the "what's new" modal.
    // response()->json(null) would serialize to "{}", not JSON null (Symfony
    // substitutes an empty ArrayObject) — return real null explicitly so
    // clients can tell "no release note yet" apart from a real one via a
    // simple falsy/truthy check instead of having to inspect for an id.
    public function latest()
    {
        $note = ReleaseNote::latest('id')->first();

        return $note
            ? response()->json($note)
            : response('null', 200)->header('Content-Type', 'application/json');
    }

    // GET /api/admin/release-notes
    public function index()
    {
        return response()->json(ReleaseNote::latest('id')->get());
    }

    // POST /api/admin/release-notes
    public function store(Request $request)
    {
        $data = $request->validate([
            'version' => 'required|string|max:50|unique:release_notes,version',
            'title'   => 'required|string|max:255',
            'notes'   => 'required|array|min:1',
            'notes.*' => 'required|string|max:500',
        ]);

        return response()->json(ReleaseNote::create($data), 201);
    }

    // PUT /api/admin/release-notes/{releaseNote}
    public function update(Request $request, ReleaseNote $releaseNote)
    {
        $data = $request->validate([
            'version' => 'string|max:50|unique:release_notes,version,' . $releaseNote->id,
            'title'   => 'string|max:255',
            'notes'   => 'array|min:1',
            'notes.*' => 'string|max:500',
        ]);

        $releaseNote->update($data);

        return response()->json($releaseNote->fresh());
    }

    // DELETE /api/admin/release-notes/{releaseNote}
    public function destroy(ReleaseNote $releaseNote)
    {
        $releaseNote->delete();

        return response()->json(['message' => 'Release note deleted']);
    }
}
