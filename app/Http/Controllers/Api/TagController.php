<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index() {
        return response()->json(Tag::withCount('shows')->orderBy('name')->get());
    }

    public function store(Request $request) {
        $data = $request->validate([
            'name'  => 'required|string|unique:tags,name',
            'color' => 'nullable|string',
        ]);
        return response()->json(Tag::create($data), 201);
    }

    public function destroy(Tag $tag) {
        $tag->delete();
        return response()->json(['message' => 'Tag deleted']);
    }

    public function attachShow(Request $request, Tag $tag) {
        $request->validate(['show_id' => 'required|exists:shows,id']);
        $tag->shows()->syncWithoutDetaching([$request->show_id]);
        return response()->json(['message' => 'Attached']);
    }

    public function detachShow(Request $request, Tag $tag) {
        $request->validate(['show_id' => 'required|exists:shows,id']);
        $tag->shows()->detach($request->show_id);
        return response()->json(['message' => 'Detached']);
    }
}