<?php

namespace Tests\Feature;

use App\Models\SearchableChunk;
use App\Models\User;
use App\Search\SearchIndexer;
use App\Search\SourceDocument;
use App\Services\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearcherSearchTest extends TestCase
{
    use RefreshDatabase;

    /** Every text sent to be embedded, to check unchanged passages are skipped. */
    private array $embedded = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.embeddings.dimensions' => 3, 'services.researcher.synthesis_enabled' => false]);

        // Three-topic "embeddings": a passage points towards whichever topics it names.
        $test = $this;
        $this->app->instance(AiClient::class, new class($test) extends AiClient {
            public function __construct(private readonly ResearcherSearchTest $test)
            {
                parent::__construct();
            }

            public function hasApiKey(): bool
            {
                return true;
            }

            public function embeddingSignature(): string
            {
                return 'fake:3';
            }

            public function embedBatch(array $texts, bool $isQuery = false): array
            {
                $this->test->recordEmbedded($texts);

                return array_map(function ($text) {
                    $v = [
                        (float) substr_count(strtolower($text), 'budget'),
                        (float) substr_count(strtolower($text), 'health'),
                        (float) substr_count(strtolower($text), 'football'),
                    ];
                    $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1;

                    return array_map(fn ($x) => $x / $norm, $v);
                }, $texts);
            }
        });
    }

    public function recordEmbedded(array $texts): void
    {
        array_push($this->embedded, ...$texts);
    }

    private function document(string $id, string $text, array $overrides = []): SourceDocument
    {
        return new SourceDocument(...array_merge([
            'id' => $id,
            'outlet' => 'NTV Uganda',
            'title' => "Story {$id}",
            'text' => $text,
            'url' => "https://ntv.co.ug/{$id}",
            'publishedAt' => '2026-11-01T08:00:00',
        ], $overrides));
    }

    private function search(array $params)
    {
        return $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/researcher/search', $params);
    }

    public function test_wordpress_posts_are_indexed_and_unchanged_ones_are_not_embedded_again(): void
    {
        Http::fake([
            'ntv.co.ug/*' => Http::response([
                ['id' => 1, 'link' => 'https://ntv.co.ug/a', 'date_gmt' => '2026-11-01T08:00:00',
                    'title' => ['rendered' => 'Budget &amp; you'], 'content' => ['rendered' => '<p>The budget was read.</p>']],
                ['id' => 2, 'link' => 'https://ntv.co.ug/b', 'date_gmt' => '2026-11-02T08:00:00',
                    'title' => ['rendered' => 'Clinics'], 'content' => ['rendered' => '<p>Health workers strike.</p>']],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $this->artisan('ingest:source', ['sources' => ['ntv']])->assertSuccessful();

        $this->assertSame(2, SearchableChunk::count());
        $chunk = SearchableChunk::where('document_id', 'ntv-uganda:1')->first();
        $this->assertSame('ntv-uganda:1:0', $chunk->external_id);
        $this->assertSame('Budget & you', $chunk->source_title);
        $this->assertSame('The budget was read.', $chunk->chunk_content);
        $this->assertCount(2, $this->embedded);

        $this->artisan('ingest:source', ['sources' => ['ntv']])->assertSuccessful();
        $this->assertCount(2, $this->embedded, 'Unchanged posts must not be embedded again');
    }

    public function test_an_edited_story_is_re_embedded_and_passages_it_lost_are_removed(): void
    {
        $indexer = app(SearchIndexer::class);
        $long = implode(' ', array_fill(0, 400, 'budget.'));

        $this->assertSame(2, $indexer->index($this->document('ntv-uganda:9', $long)));
        $this->assertSame(2, SearchableChunk::where('document_id', 'ntv-uganda:9')->count());

        $this->assertSame(1, $indexer->index($this->document('ntv-uganda:9', 'Health budget revised.')));
        $this->assertSame(['Health budget revised.'], SearchableChunk::where('document_id', 'ntv-uganda:9')->pluck('chunk_content')->all());
    }

    public function test_search_ranks_by_meaning_filters_by_outlet_and_cites_the_outlet(): void
    {
        $indexer = app(SearchIndexer::class);
        $indexer->index($this->document('ntv-uganda:1', 'The budget allocates more to roads.'));
        $indexer->index($this->document('ntv-uganda:2', 'Football league resumes this weekend.'));
        $indexer->index($this->document('kfm:3', 'Budget debate continues in parliament.', ['outlet' => 'KFM']));

        $results = collect($this->search(['query' => 'budget'])->assertOk()->json('timeline'))->flatMap(fn ($g) => $g['results']);
        $this->assertEqualsCanonicalizing(['ntv-uganda:1', 'kfm:3'], $results->map(fn ($r) => SearchableChunk::find($r['id'])->document_id)->all());

        $kfmOnly = collect($this->search(['query' => 'budget', 'brand' => 'KFM'])->json('timeline'))->flatMap(fn ($g) => $g['results']);
        $this->assertSame(['KFM'], $kfmOnly->pluck('brand_name')->unique()->values()->all());
    }

    public function test_paywalled_stories_are_searchable_but_only_show_a_teaser(): void
    {
        $story = 'Budget ' . implode(' ', array_fill(0, 120, 'details'));
        app(SearchIndexer::class)->index($this->document('daily-monitor:5', $story, ['outlet' => 'Daily Monitor', 'accessLevel' => 'subscribers']));

        $result = $this->search(['query' => 'budget'])->assertOk()->json('timeline.0.results.0');

        $this->assertSame('subscribers', $result['access_level']);
        $this->assertLessThan(60, str_word_count($result['excerpt']));
    }

    public function test_legacy_mysql_passages_are_copied_without_re_embedding(): void
    {
        DB::table('searchable_chunks')->insert([
            'brand_name' => 'KFM', 'source_title' => 'Old story', 'source_url' => 'https://www.kfm.co.ug/old',
            'external_id' => 'kfm:77:0', 'chunk_index' => 0, 'chunk_content' => 'Budget talk.',
            'embedding' => json_encode([1.0, 0.0, 0.0]), 'published_at' => '2026-10-01 08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('search:import-legacy')->assertSuccessful();

        $chunk = SearchableChunk::firstWhere('external_id', 'kfm:77:0');
        $this->assertSame('kfm:77', $chunk->document_id);
        $this->assertSame('KFM', $chunk->outlet);

        app(SearchIndexer::class)->index($this->document('kfm:77', 'Budget talk.', ['outlet' => 'KFM']));
        $this->assertSame([], $this->embedded, 'Imported vectors must be reused, not re-embedded');
    }
}
