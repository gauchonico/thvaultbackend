<?php

namespace Tests\Feature;

use App\Models\EpaperEdition;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EpaperTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.7\nfake newspaper body that is long enough to split into chunks\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** Runs the admin's chunked upload and returns the saved edition's JSON. */
    private function uploadEdition(string $body = self::PDF, array $fields = []): \Illuminate\Testing\TestResponse
    {
        $admin = $this->admin();
        $uploadId = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/epaper/uploads')
            ->assertOk()->json('upload_id');

        $chunks = str_split($body, 20);
        foreach ($chunks as $i => $chunk) {
            $this->actingAs($admin, 'sanctum')->post("/api/admin/epaper/uploads/$uploadId/chunks", [
                'index' => $i,
                'chunk' => UploadedFile::fake()->createWithContent('blob', $chunk),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $response = $this->actingAs($admin, 'sanctum')->post('/api/admin/epaper/editions', $fields + [
            'publication_id' => Publication::where('slug', 'daily-nation')->value('id'),
            'issue_date'     => '2026-11-05',
            'upload_id'      => $uploadId,
            'total_chunks'   => count($chunks),
            'page_count'     => 48,
            'access_level'   => 'subscribers',
            'cover'          => UploadedFile::fake()->image('cover.jpg', 600, 800),
        ], ['Accept' => 'application/json']);

        // Later requests in a test should be anonymous unless they act as someone.
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_the_three_titles_are_seeded(): void
    {
        $this->assertSame(
            ['daily-nation', 'business-daily', 'the-eastafrican'],
            Publication::orderBy('sort_order')->pluck('slug')->all()
        );
    }

    public function test_chunks_are_joined_into_the_edition_pdf(): void
    {
        $edition = EpaperEdition::find($this->uploadEdition()->assertCreated()->json('id'));

        Storage::disk('public')->assertExists($edition->file_path);
        $this->assertSame(self::PDF, Storage::disk('public')->get($edition->file_path));
        $this->assertSame(strlen(self::PDF), $edition->file_size);
        $this->assertSame([], Storage::disk('public')->allFiles('epaper/uploads'));
    }

    public function test_a_non_pdf_upload_is_rejected(): void
    {
        $this->uploadEdition('this is not a newspaper at all')->assertUnprocessable();
        $this->assertSame(0, EpaperEdition::count());
    }

    public function test_a_missing_chunk_is_reported(): void
    {
        $admin = $this->admin();
        $uploadId = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/epaper/uploads')->json('upload_id');

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/epaper/editions', [
            'publication_id' => Publication::value('id'),
            'issue_date'     => '2026-11-05',
            'upload_id'      => $uploadId,
            'total_chunks'   => 2,
            'page_count'     => 4,
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'The upload is incomplete (part 1 of 2 is missing). Please upload the PDF again.']);
    }

    public function test_newsstand_shows_each_titles_latest_edition_without_the_file_path(): void
    {
        $id = $this->uploadEdition()->json('id');

        $response = $this->getJson('/api/epaper/newsstand')->assertOk()
            ->assertJsonPath('dates', ['2026-11-05'])
            ->assertJsonPath('publications.0.edition.id', $id)
            ->assertJsonPath('publications.1.edition', null);

        $this->assertArrayNotHasKey('file_path', $response->json('publications.0.edition'));
        $this->getJson('/api/epaper/newsstand?date=2026-11-04')->assertJsonPath('publications.0.edition', null);
    }

    public function test_subscriber_editions_are_gated(): void
    {
        $id = $this->uploadEdition()->json('id');

        $this->getJson("/api/epaper/editions/$id")->assertOk()->assertJsonPath('edition.access.reason', 'login');
        $this->getJson("/api/epaper/editions/$id/file")->assertUnauthorized();

        $reader = User::factory()->create();
        $this->actingAs($reader, 'sanctum')->getJson("/api/epaper/editions/$id/file")
            ->assertForbidden()->assertJsonPath('reason', 'subscribe');

        EpaperEdition::whereKey($id)->update(['access_level' => 'free']);
        $response = $this->actingAs($reader, 'sanctum')->get("/api/epaper/editions/$id/file")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_unpublished_editions_are_hidden_from_readers(): void
    {
        $id = $this->uploadEdition(fields: ['is_published' => '0'])->json('id');

        $this->getJson("/api/epaper/editions/$id")->assertNotFound();
        $this->getJson('/api/epaper/newsstand')->assertJsonPath('publications.0.edition', null);
        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/epaper/editions/$id")->assertOk();
    }

    public function test_deleting_an_edition_removes_its_files(): void
    {
        $edition = EpaperEdition::find($this->uploadEdition()->json('id'));

        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/admin/epaper/editions/{$edition->id}")->assertOk();

        Storage::disk('public')->assertMissing($edition->file_path);
        Storage::disk('public')->assertMissing($edition->getRawOriginal('cover'));
    }
}
