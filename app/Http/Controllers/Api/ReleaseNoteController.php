<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReleaseNote;
use Illuminate\Http\Request;

class ReleaseNoteController extends Controller
{
    // GET /api/release-notes/latest (public) — powers the "what's new" modal.
    public function latest()
    {
        return response()->json(ReleaseNote::latest('id')->first());
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
