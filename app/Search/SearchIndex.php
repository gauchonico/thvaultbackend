<?php

namespace App\Search;

use App\Models\SearchableChunk;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the passages that best match a query. On Postgres this is hybrid
 * search: pgvector's nearest neighbours by meaning, plus full-text matches so
 * exact names ("Mpuuga", "NSSF", "Kiteezi") that embeddings blur still surface,
 * merged with reciprocal rank fusion. Elsewhere (tests, local setups without
 * pgvector) it falls back to scoring recent passages in PHP.
 */
class SearchIndex
{
    // How far down each list (meaning, keywords) to look before merging.
    private const POOL = 100;
    // Reciprocal rank fusion constant: dampens the gap between neighbouring ranks.
    private const RRF_K = 60;
    // Fallback only: how many recent passages to score in PHP.
    private const FALLBACK_CANDIDATES = 4000;

    /**
     * Best passage per document, best first.
     *
     * @param float[] $vector The embedded query.
     * @param array{outlet?: ?string, date_from?: ?string, date_to?: ?string} $filters
     * @return Collection<int, array{chunk: SearchableChunk, score: float, keyword: bool}>
     *         score is cosine similarity; keyword is whether the passage matched the words.
     */
    public function search(string $query, array $vector, array $filters, int $limit): Collection
    {
        $connection = DB::connection(config('database.search_connection'));

        $results = $connection->getDriverName() === 'pgsql'
            ? $this->hybrid($connection, $query, $vector, $filters)
            : $this->bruteForce($vector, $filters);

        return $results
            ->unique(fn ($item) => $item['chunk']->document_id)
            ->take($limit)
            ->values();
    }

    private function hybrid(ConnectionInterface $connection, string $query, array $vector, array $filters): Collection
    {
        $vector = json_encode($vector);
        [$where, $bindings] = $this->filterSql($filters);

        // pgvector 0.8+ keeps scanning the HNSW index until enough rows pass the
        // filters; older versions may return fewer than POOL when filtering.
        if (version_compare($this->pgvectorVersion($connection), '0.8.0', '>=')) {
            $connection->statement("SET hnsw.iterative_scan = 'relaxed_order'");
        }

        $rows = $connection->select(<<<SQL
            WITH semantic AS (
                SELECT id, row_number() OVER (ORDER BY distance) AS rank
                FROM (
                    SELECT id, embedding <=> ?::vector AS distance
                    FROM search_chunks
                    WHERE embedding IS NOT NULL {$where}
                    ORDER BY distance
                    LIMIT ?
                ) nearest
            ),
            keyword AS (
                SELECT id, row_number() OVER (ORDER BY score DESC) AS rank
                FROM (
                    SELECT id, ts_rank_cd(search, websearch_to_tsquery('english', ?)) AS score
                    FROM search_chunks
                    WHERE search @@ websearch_to_tsquery('english', ?) {$where}
                    ORDER BY score DESC
                    LIMIT ?
                ) matches
            )
            SELECT c.id, c.outlet, c.source_type, c.document_id, c.external_id, c.chunk_index,
                   c.source_title, c.source_url, c.source_ref, c.chunk_content, c.access_level,
                   c.published_at, c.metadata,
                   1 - (c.embedding <=> ?::vector) AS similarity,
                   k.id IS NOT NULL AS keyword_hit
            FROM search_chunks c
            LEFT JOIN semantic s ON s.id = c.id
            LEFT JOIN keyword k ON k.id = c.id
            WHERE s.id IS NOT NULL OR k.id IS NOT NULL
            ORDER BY coalesce(1.0 / (? + s.rank), 0) + coalesce(1.0 / (? + k.rank), 0) DESC
            SQL,
            [
                $vector, ...$bindings, self::POOL,
                $query, $query, ...$bindings, self::POOL,
                $vector, self::RRF_K, self::RRF_K,
            ]
        );

        return collect($rows)->map(fn ($row) => [
            'chunk'   => (new SearchableChunk)->newFromBuilder((array) $row),
            'score'   => (float) $row->similarity,
            'keyword' => (bool) $row->keyword_hit,
        ]);
    }

    private function bruteForce(array $vector, array $filters): Collection
    {
        return SearchableChunk::query()
            ->whereNotNull('embedding')
            ->when($filters['outlet'] ?? null, fn ($q, $outlet) => $q->where('outlet', $outlet))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('published_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('published_at', '<=', $date))
            ->orderByDesc('published_at')
            ->limit(self::FALLBACK_CANDIDATES)
            ->get()
            ->map(fn (SearchableChunk $chunk) => [
                'chunk'   => $chunk,
                'score'   => $chunk->similarityTo($vector),
                'keyword' => false,
            ])
            ->sortByDesc('score');
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function filterSql(array $filters): array
    {
        $where = '';
        $bindings = [];

        foreach ([
            'outlet'    => 'outlet = ?',
            'date_from' => 'published_at >= ?',
            'date_to'   => 'published_at <= ?',
        ] as $key => $clause) {
            if (!empty($filters[$key])) {
                $where .= " AND {$clause}";
                $bindings[] = $filters[$key];
            }
        }

        return [$where, $bindings];
    }

    private function pgvectorVersion(ConnectionInterface $connection): string
    {
        static $version;

        return $version ??= (string) $connection->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
    }
}
