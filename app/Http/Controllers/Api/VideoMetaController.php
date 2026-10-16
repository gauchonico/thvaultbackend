<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Admin episode auto-fill for YouTube links. Runs here, not in the browser, so the
 * YouTube Data API key stays private. (NTV VOD links are looked up directly by the
 * admin page — MediaCMS's API is public and CORS-open.)
 */
class VideoMetaController extends Controller
{
    // GET /api/admin/video-meta?url=
    public function show(Request $request)
    {
        $data = $request->validate(['url' => 'required|string|max:2048']);

        $id = $this->youTubeId($data['url']);
        if (!$id) {
            return response()->json(['message' => 'Not a YouTube link.'], 422);
        }

        $key = config('services.youtube.key');
        if (!$key) {
            return response()->json(['message' => 'YouTube lookups are not configured on the server.'], 503);
        }

        $response = Http::timeout(15)->get('https://www.googleapis.com/youtube/v3/videos', [
            'part' => 'snippet,contentDetails',
            'id'   => $id,
            'key'  => $key,
        ]);

        if ($response->failed()) {
            report(new \RuntimeException('YouTube Data API failed: ' . $response->body()));
            return response()->json(['message' => "Couldn't reach YouTube. Fill the details in by hand."], 502);
        }

        $video = $response->json('items.0');
        if (!$video) {
            return response()->json(['message' => "That YouTube video wasn't found (private or deleted?)."], 404);
        }

        $thumbs = $video['snippet']['thumbnails'] ?? [];
        $seconds = $this->seconds($video['contentDetails']['duration'] ?? null);

        return response()->json([
            'title'       => $video['snippet']['title'] ?? null,
            'description' => $video['snippet']['description'] ?? null,
            'thumbnail'   => ($thumbs['maxres'] ?? $thumbs['standard'] ?? $thumbs['high'] ?? $thumbs['medium'] ?? [])['url'] ?? null,
            // Live streams report P0D — leave the duration for the admin to fill.
            'duration'    => $seconds ? $this->formatDuration($seconds) : null,
            // Upload date as a calendar day in Uganda, so a 1am EAT upload isn't dated the day before.
            'airedAt'     => isset($video['snippet']['publishedAt'])
                ? Carbon::parse($video['snippet']['publishedAt'])->setTimezone('Africa/Kampala')->toDateString()
                : null,
        ]);
    }

    // watch?v=ID, youtu.be/ID, /embed/ID, /shorts/ID, /live/ID, m./music. hosts
    private function youTubeId(string $raw): ?string
    {
        $url = preg_match('#^https?://#i', $raw) ? $raw : "https://{$raw}";
        $parts = parse_url($url);
        $host = preg_replace('/^(www\.|m\.|music\.)/', '', strtolower($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';

        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0] ?: null;
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?\#]+)#', $path, $m)) {
                $id = $m[1];
            }
        }

        return $id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    // ISO 8601 "PT1H2M3S" → seconds
    private function seconds(?string $iso): int
    {
        try {
            return $iso ? (int) CarbonInterval::make($iso)->totalSeconds : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    // Same format as the NTV VOD auto-fill: 1258 → "21m", 3900 → "1h 5m"
    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = (int) round(($seconds % 3600) / 60);
        if (!$h) {
            return max($m, 1) . 'm';
        }
        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
}
