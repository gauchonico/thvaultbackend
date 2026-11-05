<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Researcher Portal's search index, in its own Postgres + pgvector database
 * (see database.search_connection). One row per passage from any outlet — web
 * articles today, ePaper pages and broadcast transcripts later — so every
 * source is searched, filtered and cited the same way.
 *
 * Replaces the MySQL searchable_chunks table, which is left in place until
 * search:import-legacy has copied its vectors across.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('database.search_connection');
    }

    public function up(): void
    {
        $pgsql = DB::connection($this->getConnection())->getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::connection($this->getConnection())->statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::connection($this->getConnection())->create('search_chunks', function (Blueprint $table) use ($pgsql) {
            $table->id();

            // "NTV Uganda", "KFM", "Daily Monitor", "Daily Nation"…
            $table->string('outlet');
            // article | epaper_page | transcript
            $table->string('source_type', 32)->default('article');

            // One story, page or broadcast, e.g. "ntv-uganda:48213"; its passages are
            // "{document_id}:{chunk_index}".
            $table->string('document_id');
            $table->string('external_id')->unique();
            $table->unsignedInteger('chunk_index')->default(0);

            $table->string('source_title', 1024);
            $table->string('source_url', 2048)->nullable();
            // Where a citation points inside the source when a URL isn't enough:
            // {"edition_id": 12, "page": 4} or {"media_id": 7, "start": 312.5}.
            $table->json('source_ref')->nullable();

            $table->text('chunk_content');
            // Hash of the passage and the embedding model, so re-ingesting unchanged
            // text never pays for a second embedding.
            $table->char('content_hash', 64)->nullable();

            // public | subscribers — paywalled text is searchable, but researchers
            // only see an excerpt and a link to the outlet.
            $table->string('access_level', 16)->default('public');

            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            if ($pgsql) {
                $table->vector('embedding', config('services.embeddings.dimensions'))->nullable();
                // Keyword half of hybrid search: headline words outrank body words.
                $table->tsvector('search')->storedAs(
                    "setweight(to_tsvector('english', coalesce(source_title, '')), 'A') || "
                    . "setweight(to_tsvector('english', chunk_content), 'B')"
                );
            } else {
                $table->json('embedding')->nullable();
            }

            $table->index('document_id');
            $table->index(['outlet', 'published_at']);
            $table->index('published_at');
        });

        if ($pgsql) {
            Schema::connection($this->getConnection())->table('search_chunks', function (Blueprint $table) {
                $table->vectorIndex('embedding');
                $table->index('search')->algorithm('gin');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('search_chunks');
    }
};
