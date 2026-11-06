<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminPartnerController extends Controller
{
    public function index(): JsonResponse
    {
        $partners = Partner::withCount(['followers', 'shows'])->orderBy('sort_order')->orderBy('name')->get()
            ->each->makeVisible(['revenue_share_percent', 'contact_name', 'contact_email']);

        return response()->json($partners);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] ??= (int) Partner::max('sort_order') + 1;
        $this->storeImages($request, $data);

        return response()->json($this->present(Partner::create($data)), 201);
    }

    public function update(Request $request, Partner $partner): JsonResponse
    {
        $data = $this->validated($request);
        $this->storeImages($request, $data, $partner);
        $partner->update($data);

        return response()->json($this->present($partner->fresh()));
    }

    public function destroy(Partner $partner): JsonResponse
    {
        foreach (['logo', 'banner'] as $field) {
            if ($path = $partner->getRawOriginal($field)) Storage::disk('public')->delete($path);
        }
        // Shows stay; they just lose their partner (shows.partner_id is null on delete).
        $partner->delete();

        return response()->json(['message' => 'Partner deleted']);
    }

    private function validated(Request $request): array
    {
        $request->merge(['is_active' => $request->boolean('is_active', true)]);

        return $request->validate([
            'name'                  => 'required|string|max:120',
            'type'                  => 'required|in:club,creator,studio',
            'sport'                 => 'nullable|string|max:60',
            'tagline'               => 'nullable|string|max:160',
            'description'           => 'nullable|string|max:3000',
            'accent_color'          => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'revenue_share_percent' => 'nullable|numeric|min:0|max:100',
            'contact_name'          => 'nullable|string|max:120',
            'contact_email'         => 'nullable|email|max:160',
            'is_active'             => 'boolean',
            'sort_order'            => 'nullable|integer|min:0',
            'logo'                  => 'nullable|image|max:2048',
            'banner'                => 'nullable|image|max:5120',
        ]);
    }

    private function storeImages(Request $request, array &$data, ?Partner $partner = null): void
    {
        foreach (['logo', 'banner'] as $field) {
            unset($data[$field]);
            if (!$request->hasFile($field)) continue;
            if ($old = $partner?->getRawOriginal($field)) Storage::disk('public')->delete($old);
            $data[$field] = $request->file($field)->store('partners', 'public');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'partner';
        $slug = $base;
        for ($i = 2; Partner::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function present(Partner $partner): Partner
    {
        return $partner->loadCount(['followers', 'shows'])->makeVisible(['revenue_share_percent', 'contact_name', 'contact_email']);
    }
}
