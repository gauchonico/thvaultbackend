<?php

namespace App\Services;

use App\Models\SearchableChunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WordPressIngestionService
{
    private const WORDS_PER_CHUNK = 300;
    private const PER_PAGE = 20;

    // Both NTV's and KFM's WordPress sites 406-block requests with no (or a
    // non-browser) User-Agent — this is required, not cosmetic.
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Brief pause between page fetches so a ~10k-22k post crawl doesn't hammer
    // their server hard enough to trip additional WAF/rate-limit blocking.
    private const REQUEST_DELAY_MICROSECONDS = 250_000;

    public function __construct(private readonly AiClient $ai)
    {
    }

    /**
     * Ingest posts from a WordPress site's REST API into searchable_chunks.
     *
     * @param string $brand  Display name, e.g. "NTV Uganda"
     * @param string $domain Base site URL, e.g. "https://ntv.co.ug" (no trailing slash / wp-json suffix)
     * @param int|null $maxPosts Stop after this many posts total — use for a sample run before a full crawl.
     * @param string|null $publishedAfter ISO8601 date — only ingest posts published after this (incremental re-runs).
     * @return array{posts_seen: int, posts_ingested: int, chunks_upserted: int, errors: array<int, string>}
     */
    public function ingest(
        string $brand,
        string $domain,
        ?callable $onProgress = null,
        ?int $maxPosts = null,
        ?string $publishedAfter = null,
    ): array {
        if (!$this->ai->hasApiKey()) {
            throw new \RuntimeException(
                'No embeddings provider is configured — set EMBEDDINGS_PROVIDER=ollama (with `ollama serve` '
                . 'running) or OPENAI_API_KEY in .env before running ingestion.'
            );
        }

        $domain = rtrim($domain, '/');
        $brandSlug = Str::slug($brand);

        $stats = ['posts_seen' => 0, 'posts_ingested' => 0, 'chunks_upserted' => 0, 'errors' => []];

        $page = 1;
        $totalPages = 1;

        do {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(30)
                ->retry(3, 2000, throw: false)
                ->get("{$domain}/wp-json/wp/v2/posts", array_filter([
                    '_embed'   => 1,
                    'per_page' => self::PER_PAGE,
                    'page'     => $page,
                    'after'    => $publishedAfter,
                ]));

            if ($response->failed()) {
                $stats['errors'][] = "Failed to fetch page {$page} from {$domain}: HTTP {$response->status()}";
                break;
            }

            $totalPages = (int) $response->header('X-WP-TotalPages', '1') ?: 1;
            $posts = $response->json() ?? [];

            foreach ($posts as $post) {
                if ($maxPosts !== null && $stats['posts_seen'] >= $maxPosts) {
                    break 2;
                }

                $stats['posts_seen']++;
                try {
                    $chunksUpserted = $this->ingestPost($brand, $brandSlug, $post);
                    $stats['chunks_upserted'] += $chunksUpserted;
                    $stats['posts_ingested']++;
                } catch (Throwable $e) {
                    Log::warning("Researcher ingestion: failed on post {$post['id']} ({$brand})", [
                        'message' => $e->getMessage(),
                    ]);
                    $stats['errors'][] = "Post #{$post['id']}: {$e->getMessage()}";
                }

                if ($onProgress) {
                    $onProgress($stats);
                }
            }

            $page++;
            if ($page <= $totalPages) {
                usleep(self::REQUEST_DELAY_MICROSECONDS);
            }
        } while ($page <= $totalPages);

        return $stats;
    }

    /**
     * @return int Number of chunks upserted for this post.
     */
    private function ingestPost(string $brand, string $brandSlug, array $post): int
    {
        $title = $this->decodeAndClean($post['title']['rendered'] ?? '');
        $rawContent = $post['content']['rendered'] ?? '';
        $cleanContent = $this->cleanHtml($rawContent);

        if ($cleanContent === '') {
            return 0;
        }

        $chunks = $this->chunkText($cleanContent);
        if (empty($chunks)) {
            return 0;
        }

        $embeddings = $this->ai->embedBatch($chunks);

        $metadata = [
            'author'         => $post['_embedded']['author'][0]['name'] ?? null,
            'categories'     => collect($post['_embedded']['wp:term'][0] ?? [])
                ->pluck('name')->values()->all(),
            'featured_image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null,
        ];

        $publishedAt = $post['date_gmt'] ?? $post['date'] ?? null;
        $upserted = 0;

        foreach ($chunks as $index => $chunkContent) {
            $externalId = "{$brandSlug}:{$post['id']}:{$index}";

            SearchableChunk::updateOrCreate(
                ['external_id' => $externalId],
                [
                    'brand_name'    => $brand,
                    'source_title'  => $title,
                    'source_url'    => $post['link'] ?? '',
                    'chunk_index'   => $index,
                    'chunk_content' => $chunkContent,
                    'embedding'     => $embeddings[$index] ?? null,
                    'published_at'  => $publishedAt,
                    'metadata'      => $metadata,
                ]
            );
            $upserted++;
        }

        return $upserted;
    }

    private function cleanHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function decodeAndClean(string $html): string
    {
        return $this->cleanHtml($html);
    }

    /**
     * Split text into ~300-word chunks, breaking on sentence boundaries where
     * possible so a chunk doesn't cut a sentence in half mid-thought.
     *
     * @return string[]
     */
    private function chunkText(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        $chunks = [];
        $current = [];
        $wordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWordCount = str_word_count($sentence);

            if ($wordCount > 0 && $wordCount + $sentenceWordCount > self::WORDS_PER_CHUNK) {
                $chunks[] = trim(implode(' ', $current));
                $current = [];
                $wordCount = 0;
            }

            $current[] = $sentence;
            $wordCount += $sentenceWordCount;
        }

        if (!empty($current)) {
            $chunks[] = trim(implode(' ', $current));
        }

        return array_values(array_filter($chunks, fn ($c) => $c !== ''));
    }
}
