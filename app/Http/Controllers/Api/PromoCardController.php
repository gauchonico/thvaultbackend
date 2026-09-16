<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PromoCardController extends Controller
{
    // GET /api/promo-cards (public) — powers the homepage promo section.
    public function index()
    {
        return response()->json(
            PromoCard::with('genre')->orderBy('order')->get()
        );
    }

    // POST /api/admin/promo-cards
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'    => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image'    => 'nullable|image|max:5120',
            'genre_id' => 'nullable|exists:genres,id',
            'order'    => 'nullable|integer',
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('promos', 'public');
        }

        $card = PromoCard::create($data);

        return response()->json($card->load('genre'), 201);
    }

    // PUT /api/admin/promo-cards/{promoCard}
    public function update(Request $request, PromoCard $promoCard)
    {
        $data = $request->validate([
            'title'    => 'string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image'    => 'nullable|image|max:5120',
            'genre_id' => 'nullable|exists:genres,id',
            'order'    => 'nullable|integer',
        ]);

        if ($request->hasFile('image')) {
            if ($oldImage = $promoCard->getRawOriginal('image')) {
                Storage::disk('public')->delete($oldImage);
            }
            $data['image'] = $request->file('image')->store('promos', 'public');
        }

        $promoCard->update($data);

        return response()->json($promoCard->load('genre'));
    }

    // DELETE /api/admin/promo-cards/{promoCard}
    public function destroy(PromoCard $promoCard)
    {
        if ($image = $promoCard->getRawOriginal('image')) {
            Storage::disk('public')->delete($image);
        }

        $promoCard->delete();

        return response()->json(['message' => 'Promo card deleted']);
    }
}
