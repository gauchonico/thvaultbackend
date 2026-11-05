<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EpaperEdition;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The public ePaper: the newsstand of today's front pages, a title's back issues,
 * and the reader's PDF (streamed only after an access check).
 */
class EpaperController extends Controller
{
    // GET /api/epaper/newsstand?date=YYYY-MM-DD (public)
    // Without a date each title shows its newest issue; with one, that day's issue (or none).
    public function newsstand(Request $request)
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $data['date'] ?? null;

        $publications = Publication::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->when(!$date, fn ($q) => $q->with('latestEdition'))
            ->get();

        $onDate = $date
            ? EpaperEdition::where('is_published', true)->whereDate('issue_date', $date)
                // The main paper (no title) before its supplements.
                ->orderByRaw('title is not null')->orderBy('id')
                ->get()->groupBy('publication_id')
            : collect();

        $publications->each(function (Publication $publication) use ($date, $onDate) {
            $edition = $date ? $onDate->get($publication->id)?->first() : $publication->latestEdition;
            $publication->unsetRelation('latestEdition');
            $publication->setAttribute('edition', $edition);
        });

        $dates = EpaperEdition::where('is_published', true)
            ->select('issue_date')->distinct()
            ->orderByDesc('issue_date')->limit(30)
            ->pluck('issue_date')->map->format('Y-m-d');

        return response()->json([
            'date'         => $date,
            'dates'        => $dates,
            'publications' => $publications,
        ]);
    }

    // GET /api/epaper/publications/{publication:slug} (public) — a title's back issues
    public function publication(Publication $publication)
    {
        abort_unless($publication->is_active, 404);

        $editions = $publication->editions()
            ->where('is_published', true)
            ->orderByDesc('issue_date')->orderByRaw('title is not null')->orderBy('id')
            ->paginate(24);

        return response()->json(['publication' => $publication, 'editions' => $editions]);
    }

    // GET /api/epaper/editions/{edition} (public; reads the token when one is sent)
    public function edition(EpaperEdition $edition)
    {
        $user = auth('sanctum')->user();
        abort_unless($edition->is_published || $user?->is_admin, 404);

        $edition->load('publication');
        $edition->setAttribute('access', $edition->accessFor($user));

        $published = fn () => EpaperEdition::where('is_published', true);

        return response()->json([
            'edition' => $edition,
            // Day-before / day-after issue of the same title, for the reader's date arrows.
            'previous' => $published()->where('publication_id', $edition->publication_id)
                ->where('issue_date', '<', $edition->issue_date)->orderByDesc('issue_date')->first(['id', 'issue_date']),
            'next' => $published()->where('publication_id', $edition->publication_id)
                ->where('issue_date', '>', $edition->issue_date)->orderBy('issue_date')->first(['id', 'issue_date']),
            // Everything else on the stand that day (other titles and supplements).
            'same_day' => $published()->with('publication:id,name,slug,accent_color')
                ->whereDate('issue_date', $edition->issue_date)->where('id', '!=', $edition->id)
                ->orderBy('publication_id')->get(['id', 'publication_id', 'issue_date', 'title', 'cover']),
        ]);
    }

    // GET /api/epaper/editions/{edition}/file (auth) — the PDF itself
    public function file(Request $request, EpaperEdition $edition)
    {
        $user = $request->user();
        abort_unless($edition->is_published || $user->is_admin, 404);

        $access = $edition->accessFor($user);
        if (!$access['has_access']) {
            return response()->json([
                'message' => 'Subscribe to read this edition.',
                'reason'  => $access['reason'],
            ], 403);
        }

        $disk = Storage::disk(EpaperEdition::disk());
        if (!$disk->exists($edition->file_path)) {
            return response()->json(['message' => 'This edition is no longer available.'], 404);
        }

        $edition->loadMissing('publication');
        $name = Str::slug($edition->publication->name) . '-' . $edition->issue_date->format('Y-m-d') . '.pdf';
        $headers = [
            'Content-Type'        => 'application/pdf',
            'Cache-Control'       => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ];

        // On a local disk serve the real file: it answers Range requests, so the
        // reader can show the first pages before the whole paper has downloaded.
        $localPath = rescue(fn () => $disk->path($edition->file_path), null, false);
        if ($localPath && is_file($localPath)) {
            return response()->file($localPath, $headers);
        }

        return $disk->response($edition->file_path, $name, $headers);
    }
}
