<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HeroSlide;
use Illuminate\Http\Request;

class HeroSlideController extends Controller
{
    // GET /api/hero-slides (public)
    public function index()
    {
        $slides = HeroSlide::with([
                'show.episodes', 
                'show.genres', // Added
                'show.tags'    // Added
            ])
            ->where('active', true)
            ->orderBy('order')
            ->take(5) // Increased to 5 for a better hero experience
            ->get()
            ->pluck('show');

        return response()->json($slides);
    }

    // GET /api/admin/hero-slides
    public function adminIndex()
    {
        // Load relationships here too so the admin panel can show current tags/genres
        return response()->json(
            HeroSlide::with(['show.genres', 'show.tags'])->orderBy('order')->get()
        );
    }

    // ... store, destroy, and reorder methods remain the same logic ...
    // Just ensure they return the loaded relationships if the frontend needs them immediately
    public function store(Request $request)
    {
        $data = $request->validate([
            'show_id' => 'required|exists:shows,id',
            'order'   => 'integer',
        ]);

        HeroSlide::where('show_id', $data['show_id'])->delete();

        $slide = HeroSlide::create([
            'show_id' => $data['show_id'],
            'order' => $data['order'] ?? 0,
            'active' => true
        ]);

        return response()->json($slide->load('show.genres', 'show.tags'), 201);
    }

    // PUT /api/admin/hero-slides/{heroSlide}
    public function update(Request $request, HeroSlide $heroSlide)
    {
        $data = $request->validate([
            'show_id' => 'required|exists:shows,id',
        ]);

        // Another slide can't point at the same show.
        HeroSlide::where('show_id', $data['show_id'])
            ->where('id', '!=', $heroSlide->id)
            ->delete();

        $heroSlide->update(['show_id' => $data['show_id']]);

        return response()->json($heroSlide->load('show.genres', 'show.tags'));
    }

    // DELETE /api/admin/hero-slides/{heroSlide}
    public function destroy(HeroSlide $heroSlide)
    {
        $heroSlide->delete();

        return response()->json(['message' => 'Hero slide removed']);
    }

    // PUT /api/admin/hero-slides/reorder
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'slides'           => 'required|array',
            'slides.*.id'      => 'required|exists:hero_slides,id',
            'slides.*.order'   => 'required|integer',
        ]);

        foreach ($data['slides'] as $slide) {
            HeroSlide::where('id', $slide['id'])->update(['order' => $slide['order']]);
        }

        return response()->json(
            HeroSlide::with(['show.genres', 'show.tags'])->orderBy('order')->get()
        );
    }
}