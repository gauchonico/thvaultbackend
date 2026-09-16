<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('searchable_chunks', function (Blueprint $table) {
            $table->id();

            // e.g. "NTV Uganda", "KFM" — kept as a plain string rather than a
            // foreign key since these are external sources, not app entities.
            $table->string('brand_name');

            $table->string('source_title');
            $table->string('source_url', 2048);

            // Unique per (post, chunk index), e.g. "ntv-uganda:48213:2" —
            // lets re-ingestion upsert instead of duplicating rows.
            $table->string('external_id')->unique();

            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('chunk_content');

            // 1536-dim float vector from text-embedding-3-small, stored as JSON.
            // Phase 1 does brute-force cosine similarity in PHP — fine at this
            // scale, revisit (pgvector/Meilisearch) if the corpus grows large.
            $table->json('embedding')->nullable();

            $table->dateTime('published_at')->nullable();

            // author, categories[], featured_image_url, etc.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['brand_name', 'published_at']);
            $table->fullText(['source_title', 'chunk_content']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('searchable_chunks');
    }
};
