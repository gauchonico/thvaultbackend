<?php

namespace App\Console\Commands;

use App\Models\SearchableChunk;
use App\Search\SearchIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One-off: copies passages already embedded in the old MySQL searchable_chunks
 * table into the search index, so moving databases doesn't pay to embed them
 * again. Safe to re-run; rows already copied are updated in place.
 */
class ImportLegacySearchChunks extends Command
{
    protected $signature = 'search:import-legacy';

    protected $description = 'Copy embedded passages from the old MySQL searchable_chunks table into the search index';

    public function handle(SearchIndexer $indexer): int
    {
        if (!Schema::hasTable('searchable_chunks')) {
            $this->info('No legacy searchable_chunks table — nothing to import.');
            return self::SUCCESS;
        }

        $dimensions = config('services.embeddings.dimensions');
        $copied = 0;
        $skipped = 0;

        DB::table('searchable_chunks')->whereNotNull('embedding')->orderBy('id')->chunkById(200, function ($rows) use ($indexer, $dimensions, &$copied, &$skipped) {
            foreach ($rows as $row) {
                $embedding = json_decode($row->embedding, true);

                // Vectors from another model (e.g. 1536-dim OpenAI) can't share the index.
                if (!is_array($embedding) || count($embedding) !== $dimensions) {
                    $skipped++;
                    continue;
                }

                SearchableChunk::updateOrCreate(
                    ['external_id' => $row->external_id],
                    [
                        'outlet'        => $row->brand_name,
                        'source_type'   => 'article',
                        'document_id'   => Str::beforeLast($row->external_id, ':'),
                        'chunk_index'   => $row->chunk_index,
                        'source_title'  => $row->source_title,
                        'source_url'    => $row->source_url ?: null,
                        'chunk_content' => $row->chunk_content,
                        // Assumes the current embedding model made these vectors, so the
                        // next ingest recognises them and skips re-embedding.
                        'content_hash'  => $indexer->hash($row->chunk_content),
                        'access_level'  => 'public',
                        'published_at'  => $row->published_at,
                        'metadata'      => json_decode($row->metadata ?? 'null', true),
                        'embedding'     => $embedding,
                    ]
                );
                $copied++;
            }
        });

        $this->info("Copied {$copied} passages" . ($skipped ? ", skipped {$skipped} with {$dimensions}-dimension mismatch" : '') . '.');

        return self::SUCCESS;
    }
}
