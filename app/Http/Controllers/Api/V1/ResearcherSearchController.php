<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SearchableChunk;
use App\Search\SearchIndex;
use App\Services\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResearcherSearchController extends Controller
{
    // Closest articles (one best-matching chunk each) handed to the AI to judge.
    private const CANDIDATE_ARTICLES = 20;
    // Below this cosine score a Gemini match is noise for any query (measured: unrelated
    // articles land at 0.54–0.65, real answers 0.60–0.75), so don't even send it.
    // Keyword matches are kept regardless: an exact name is a real lead.
    private const MIN_SIMILARITY = 0.5;
    // Without the AI judge, keep only results this close to the best match.
    private const FALLBACK_MARGIN = 0.06;
    private const FALLBACK_MAX_RESULTS = 10;
    private const PASSAGE_WORDS = 80;
    // Paywalled outlets show researchers a teaser, not the full passage.
    private const SUBSCRIBER_EXCERPT_WORDS = 50;

    public function __construct(private readonly AiClient $ai, private readonly SearchIndex $index)
    {
    }

    // POST /api/v1/researcher/search
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query'     => 'required|string|min:2|max:500',
            'brand'     => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        if (!$this->ai->hasApiKey()) {
            return response()->json([
                'message' => 'Researcher search is not configured yet — set an embeddings provider on the server.',
            ], 503);
        }

        try {
            $queryVector = $this->ai->embed($data['query']);
        } catch (ConnectionException $e) {
            // The embeddings backend (e.g. a tunnelled Ollama host) couldn't be reached at
            // all — most likely it's simply offline right now, not a real application error.
            report($e);
            return response()->json([
                'message' => 'Researcher search is temporarily unavailable — our search backend is offline right now. Please check back soon.',
            ], 503);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Failed to process the search query. Please try again.'], 502);
        }

        if (empty($queryVector)) {
            return response()->json(['message' => 'Failed to process the search query. Please try again.'], 502);
        }

        $scored = $this->index
            ->search($data['query'], $queryVector, [
                'outlet'    => $data['brand'] ?? null,
                'date_from' => $data['date_from'] ?? null,
                'date_to'   => $data['date_to'] ?? null,
            ], self::CANDIDATE_ARTICLES * 2)
            ->filter(fn ($item) => $item['keyword'] || $item['score'] >= self::MIN_SIMILARITY)
            ->take(self::CANDIDATE_ARTICLES)
            ->values();

        $judged = null;
        if (config('services.researcher.synthesis_enabled') && $scored->isNotEmpty()) {
            $judged = $this->ai->rankAndSummarize(
                $data['query'],
                $scored->map(fn ($item) => [
                    'title' => $item['chunk']->source_title,
                    'brand' => $item['chunk']->outlet,
                    'date'  => optional($item['chunk']->published_at)->toDateString(),
                    'text'  => Str::words($item['chunk']->chunk_content, self::PASSAGE_WORDS),
                ])->all()
            );
        }

        if ($judged !== null) {
            // Only what the AI judged relevant, most useful first.
            $kept = collect($judged['relevant'])->map(fn ($i) => $scored[$i]);
            $summary = $judged['summary'];
        } else {
            // AI off or failed: keep keyword matches and results close to the best match.
            $best = $scored->max('score') ?? 0;
            $kept = $scored
                ->filter(fn ($item) => $item['keyword'] || $item['score'] >= $best - self::FALLBACK_MARGIN)
                ->take(self::FALLBACK_MAX_RESULTS);
            $summary = null;
        }

        $results = $kept->map(fn ($item) => $this->formatResult($item['chunk'], $item['score']))->values();

        return response()->json([
            'query'         => $data['query'],
            'brand'         => $data['brand'] ?? null,
            'date_from'     => $data['date_from'] ?? null,
            'date_to'       => $data['date_to'] ?? null,
            'summary'       => $summary,
            'total_results' => $results->count(),
            'timeline'      => $this->groupByMonth($results),
        ]);
    }

    private function formatResult(SearchableChunk $chunk, float $score): array
    {
        return [
            'id'            => $chunk->id,
            'brand_name'    => $chunk->outlet,
            'source_type'   => $chunk->source_type,
            'source_title'  => $chunk->source_title,
            'source_url'    => $chunk->source_url,
            'source_ref'    => $chunk->source_ref,
            'access_level'  => $chunk->access_level,
            'excerpt'       => $chunk->access_level === 'public'
                ? $chunk->chunk_content
                : Str::words($chunk->chunk_content, self::SUBSCRIBER_EXCERPT_WORDS),
            'published_at'  => optional($chunk->published_at)->toIso8601String(),
            'metadata'      => $chunk->metadata,
            'relevance'     => round($score, 4),
        ];
    }

    /**
     * Groups already-ranked results into a chronological timeline, most recent
     * month first, preserving each group's internal relevance ordering.
     */
    private function groupByMonth($results): array
    {
        $groups = $results->groupBy(function ($r) {
            return $r['published_at'] ? substr($r['published_at'], 0, 7) : 'undated';
        });

        return $groups->keys()
            ->sortByDesc(fn ($period) => $period) // "undated" naturally sorts last against YYYY-MM
            ->map(function ($period) use ($groups) {
                $label = $period === 'undated'
                    ? 'Undated'
                    : \Carbon\Carbon::createFromFormat('Y-m', $period)->format('F Y');

                return [
                    'period'  => $period,
                    'label'   => $label,
                    'results' => $groups[$period]->values(),
                ];
            })
            ->values()
            ->all();
    }
}
