<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Genre;
use Illuminate\Http\Request;

class GenreController extends Controller
{
    public function index() {
        return response()->json(Genre::withCount('shows')->orderBy('name')->get());
    }

    public function store(Request $request) {
        $data = $request->validate(['name' => 'required|string|unique:genres,name']);
        return response()->json(Genre::create($data), 201);
    }

    public function destroy(Genre $genre) {
        $genre->delete();
        return response()->json(['message' => 'Genre deleted']);
    }

    // Attach/detach genre to a show
    public function attachShow(Request $request, Genre $genre) {
        $request->validate(['show_id' => 'required|exists:shows,id']);
        $genre->shows()->syncWithoutDetaching([$request->show_id]);
        return response()->json(['message' => 'Attached']);
    }

    public function detachShow(Request $request, Genre $genre) {
        $request->validate(['show_id' => 'required|exists:shows,id']);
        $genre->shows()->detach($request->show_id);
        return response()->json(['message' => 'Detached']);
    }
}