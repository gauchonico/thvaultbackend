<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SearchableChunk;
use App\Services\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ResearcherSearchController extends Controller
{
    // How many most-recent candidate chunks to brute-force score per request.
    // Phase 1 ceiling — move to Meilisearch/pgvector before the corpus outgrows this.
    private const MAX_CANDIDATES = 4000;
    private const TOP_N_RESULTS = 30;
    private const SUMMARY_PASSAGE_COUNT = 5;

    public function __construct(private readonly AiClient $ai)
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

        $candidates = SearchableChunk::query()
            ->whereNotNull('embedding')
            ->when($data['brand'] ?? null, fn ($q, $brand) => $q->where('brand_name', $brand))
            ->when($data['date_from'] ?? null, fn ($q, $date) => $q->where('published_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($q, $date) => $q->where('published_at', '<=', $date))
            ->orderByDesc('published_at')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $scored = $candidates
            ->map(fn (SearchableChunk $chunk) => [
                'chunk' => $chunk,
                'score' => $chunk->similarityTo($queryVector),
            ])
            ->sortByDesc('score')
            ->take(self::TOP_N_RESULTS)
            ->values();

        $results = $scored->map(fn ($item) => $this->formatResult($item['chunk'], $item['score']));

        // Off by default for the demo — local (Ollama) synthesis takes 20s+ on CPU,
        // which risks upstream proxy timeouts. Toggle via RESEARCHER_SYNTHESIS_ENABLED.
        $summary = null;
        if (config('services.researcher.synthesis_enabled')) {
            // Truncate what goes into the prompt (not the displayed excerpt) — 5 full
            // 300-word chunks makes for a very slow local-model generation.
            $summary = $this->ai->synthesize(
                $data['query'],
                $results->take(self::SUMMARY_PASSAGE_COUNT)->map(fn ($r) => [
                    'brand' => $r['brand_name'],
                    'date'  => $r['published_at'],
                    'text'  => Str::words($r['excerpt'], 60),
                ])->all()
            );
        }

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
            'brand_name'    => $chunk->brand_name,
            'source_title'  => $chunk->source_title,
            'source_url'    => $chunk->source_url,
            'excerpt'       => $chunk->chunk_content,
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
