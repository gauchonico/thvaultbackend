<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EpisodeThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin, 'sanctum');

        $this->show = Show::forceCreate([
            'title' => 'Show', 'description' => 'Desc',
            'year' => 2026, 'rating' => 'PG',
        ]);
    }

    private function episodeFields(array $extra = []): array
    {
        return ['title' => 'Ep 1', 'season' => 1, 'episode_number' => 1, 'duration' => '21m', ...$extra];
    }

    public function test_add_episode_with_uploaded_thumbnail_stores_file(): void
    {
        $res = $this->post("/api/admin/episodes/{$this->show->id}", $this->episodeFields([
            'thumbnail' => UploadedFile::fake()->image('thumb.jpg', 640, 360),
        ]), ['Accept' => 'application/json']);

        $res->assertCreated();
        $path = Episode::first()->getRawOriginal('thumbnail');
        $this->assertStringStartsWith('thumbnails/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $res->json('thumbnail'));
    }

    public function test_add_episode_with_thumbnail_url_keeps_url(): void
    {
        $url = 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg';
        $this->postJson("/api/admin/episodes/{$this->show->id}", $this->episodeFields(['thumbnail' => $url]))
            ->assertCreated()
            ->assertJsonPath('thumbnail', $url);
    }

    public function test_update_with_new_file_replaces_and_deletes_old_file(): void
    {
        $old = UploadedFile::fake()->image('old.jpg')->store('thumbnails', 'public');
        $ep = Episode::create(['show_id' => $this->show->id, 'thumbnail' => $old, ...$this->episodeFields()]);

        $this->post("/api/admin/episodes/{$ep->id}", [
            '_method' => 'PUT',
            'thumbnail' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $new = $ep->fresh()->getRawOriginal('thumbnail');
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_update_echoing_resolved_url_keeps_stored_path(): void
    {
        $old = UploadedFile::fake()->image('old.jpg')->store('thumbnails', 'public');
        $ep = Episode::create(['show_id' => $this->show->id, 'thumbnail' => $old, ...$this->episodeFields()]);

        $this->putJson("/api/admin/episodes/{$ep->id}", ['title' => 'Renamed', 'thumbnail' => $ep->thumbnail])
            ->assertOk();

        $this->assertSame($old, $ep->fresh()->getRawOriginal('thumbnail'));
        Storage::disk('public')->assertExists($old);
    }

    public function test_update_to_url_deletes_old_file(): void
    {
        $old = UploadedFile::fake()->image('old.jpg')->store('thumbnails', 'public');
        $ep = Episode::create(['show_id' => $this->show->id, 'thumbnail' => $old, ...$this->episodeFields()]);
        $url = 'https://vod.ntv.co.ug/media/original/thumbnails/x.jpg';

        $this->putJson("/api/admin/episodes/{$ep->id}", ['thumbnail' => $url])
            ->assertOk()
            ->assertJsonPath('thumbnail', $url);

        Storage::disk('public')->assertMissing($old);
    }

    public function test_delete_episode_removes_stored_thumbnail(): void
    {
        $old = UploadedFile::fake()->image('old.jpg')->store('thumbnails', 'public');
        $ep = Episode::create(['show_id' => $this->show->id, 'thumbnail' => $old, ...$this->episodeFields()]);

        $this->deleteJson("/api/admin/episodes/{$ep->id}")->assertOk();
        Storage::disk('public')->assertMissing($old);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $this->post("/api/admin/episodes/{$this->show->id}", $this->episodeFields([
            'thumbnail' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertStatus(422);
    }
}
