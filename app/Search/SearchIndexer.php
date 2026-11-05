<?php

namespace App\Search;

use App\Models\SearchableChunk;
use App\Search\Sources\ContentSource;
use App\Services\AiClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Splits documents into passages, embeds them and writes them to the search
 * index. Re-indexing is cheap: passages whose text (and embedding model) hasn't
 * changed keep their vectors, and passages a shortened story no longer has are
 * removed.
 */
class SearchIndexer
{
    private const WORDS_PER_CHUNK = 300;

    public function __construct(private readonly AiClient $ai)
    {
    }

    /**
     * @return array{documents_seen: int, documents_changed: int, chunks_embedded: int, errors: array<int, string>}
     */
    public function run(ContentSource $source, ?int $max = null, ?string $since = null, ?callable $onProgress = null): array
    {
        if (!$this->ai->hasApiKey()) {
            throw new RuntimeException('No embeddings provider is configured — set EMBEDDINGS_PROVIDER and its API key in .env first.');
        }

        $stats = ['documents_seen' => 0, 'documents_changed' => 0, 'chunks_embedded' => 0, 'errors' => []];

        try {
            foreach ($source->documents($max, $since) as $document) {
                $stats['documents_seen']++;

                try {
                    $embedded = $this->index($document);
                    $stats['chunks_embedded'] += $embedded;
                    $stats['documents_changed'] += $embedded > 0 ? 1 : 0;
                } catch (Throwable $e) {
                    Log::warning("Search index: failed on {$document->id}", ['message' => $e->getMessage()]);
                    $stats['errors'][] = "{$document->id}: {$e->getMessage()}";
                }

                if ($onProgress) {
                    $onProgress($stats);
                }
            }
        } catch (Throwable $e) {
            $stats['errors'][] = $e->getMessage();
        }

        return $stats;
    }

    /** @return int How many passages had to be embedded (0 when nothing changed). */
    public function index(SourceDocument $document): int
    {
        $chunks = $this->chunk($document->text);
        if (empty($chunks)) {
            return 0;
        }

        $hashes = array_map(fn ($chunk) => $this->hash($chunk), $chunks);
        $stored = SearchableChunk::where('document_id', $document->id)
            ->whereNotNull('embedding')
            ->pluck('content_hash', 'chunk_index');

        $changed = array_keys(array_filter($hashes, fn ($hash, $i) => ($stored[$i] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
        $vectors = $changed
            ? array_combine($changed, $this->ai->embedBatch(array_map(fn ($i) => $chunks[$i], $changed)))
            : [];

        foreach ($chunks as $index => $text) {
            SearchableChunk::updateOrCreate(
                ['external_id' => "{$document->id}:{$index}"],
                [
                    'outlet'        => $document->outlet,
                    'source_type'   => $document->sourceType,
                    'document_id'   => $document->id,
                    'chunk_index'   => $index,
                    'source_title'  => $document->title,
                    'source_url'    => $document->url,
                    'source_ref'    => $document->ref,
                    'chunk_content' => $text,
                    'content_hash'  => $hashes[$index],
                    'access_level'  => $document->accessLevel,
                    'published_at'  => $document->publishedAt,
                    'metadata'      => $document->metadata,
                ] + (isset($vectors[$index]) ? ['embedding' => $vectors[$index]] : [])
            );
        }

        SearchableChunk::where('document_id', $document->id)->where('chunk_index', '>=', count($chunks))->delete();

        return count($changed);
    }

    /** Ties a passage to the model that embedded it, so switching models re-embeds. */
    public function hash(string $chunk): string
    {
        return hash('sha256', $this->ai->embeddingSignature() . "\n" . $chunk);
    }

    /**
     * ~300-word passages, broken on sentence boundaries where possible so a
     * passage doesn't cut a sentence in half mid-thought.
     *
     * @return string[]
     */
    private function chunk(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $current = [];
        $wordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWordCount = str_word_count($sentence);

            if ($wordCount > 0 && $wordCount + $sentenceWordCount > self::WORDS_PER_CHUNK) {
                $chunks[] = implode(' ', $current);
                $current = [];
                $wordCount = 0;
            }

            $current[] = $sentence;
            $wordCount += $sentenceWordCount;
        }

        if ($current) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }
}
