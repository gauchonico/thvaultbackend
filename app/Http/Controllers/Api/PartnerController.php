<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Show;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    // GET /api/partners?type=club — active partners for the Sports page and hubs list.
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'nullable|in:club,creator,studio']);
        $user = auth('sanctum')->user();
        $following = $user ? $user->followedPartners()->pluck('partners.id')->flip() : collect();

        $partners = Partner::where('is_active', true)
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->withCount(['followers', 'shows'])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->each(fn (Partner $p) => $p->setAttribute('is_following', $following->has($p->id)));

        return response()->json($partners);
    }

    // GET /api/partners/{slug} — a hub: the partner, its shows and whether you follow it.
    public function show(Partner $partner): JsonResponse
    {
        abort_unless($partner->is_active, 404);
        $user = auth('sanctum')->user();

        $shows = Show::with(['episodes', 'genres', 'channel'])->where('partner_id', $partner->id)->latest()->get();
        Show::applyAccess($shows, $user);

        return response()->json([
            'partner' => $partner->loadCount('followers')->setAttribute(
                'is_following', $user ? $partner->followers()->whereKey($user->id)->exists() : false
            ),
            'shows'   => $shows,
        ]);
    }

    // POST /api/partners/{slug}/follow
    public function follow(Request $request, Partner $partner): JsonResponse
    {
        abort_unless($partner->is_active, 404);
        $partner->followers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['following' => true, 'followers_count' => $partner->followers()->count()]);
    }

    // DELETE /api/partners/{slug}/follow
    public function unfollow(Request $request, Partner $partner): JsonResponse
    {
        $partner->followers()->detach($request->user()->id);

        return response()->json(['following' => false, 'followers_count' => $partner->followers()->count()]);
    }

    // GET /api/me/following — the partners you follow, newest shows first, for the home row.
    public function following(Request $request): JsonResponse
    {
        $partners = $request->user()->followedPartners()->where('is_active', true)->orderBy('name')->get();

        $shows = Show::with(['episodes', 'genres', 'channel', 'partner:id,name,slug,logo,type,accent_color'])
            ->whereIn('partner_id', $partners->pluck('id'))->latest()->limit(20)->get();
        Show::applyAccess($shows, $request->user());

        return response()->json(['partners' => $partners, 'shows' => $shows]);
    }
}
