<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VdoCipherPlaybackTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEO_ID = 'd1c78726cd6245ce8eb9e398894bd0aa';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vdocipher.secret' => 'test-secret']);
        Http::fake(['dev.vdocipher.com/*' => Http::response(['otp' => 'otp-123', 'playbackInfo' => 'info-456'])]);
    }

    private function episode(array $show = []): Episode
    {
        $show = Show::forceCreate(['title' => 'Show', 'description' => 'Desc', 'year' => 2026, 'rating' => 'PG'] + $show);
        return Episode::create([
            'show_id' => $show->id, 'title' => 'Ep 1', 'season' => 1, 'episode_number' => 1,
            'duration' => '1m', 'video_url' => self::VIDEO_ID,
        ]);
    }

    public function test_a_pasted_video_id_is_stored_as_a_vdocipher_link(): void
    {
        $this->assertSame('vdocipher:' . self::VIDEO_ID, $this->episode()->video_url);
    }

    public function test_viewer_with_access_gets_a_watermarked_otp(): void
    {
        $user = User::factory()->create(['email' => 'viewer@example.com']);
        $ep = $this->episode();

        $this->actingAs($user, 'sanctum')->postJson("/api/episodes/{$ep->id}/playback")
            ->assertOk()
            ->assertJson(['provider' => 'vdocipher', 'otp' => 'otp-123', 'playbackInfo' => 'info-456']);

        Http::assertSent(fn (Request $r) =>
            str_ends_with($r->url(), '/videos/' . self::VIDEO_ID . '/otp')
            && $r->header('Authorization')[0] === 'Apisecret test-secret'
            && str_contains($r['annotate'], 'viewer@example.com'));
    }

    public function test_viewer_without_access_gets_no_otp(): void
    {
        $ep = $this->episode(['rent_price_ugx' => 5000]);

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/episodes/{$ep->id}/playback")
            ->assertForbidden()
            ->assertJsonPath('reason', 'purchase');
        Http::assertNothingSent();
    }

    public function test_signed_out_visitors_get_no_otp(): void
    {
        $this->postJson("/api/episodes/{$this->episode()->id}/playback")->assertUnauthorized();
    }

    public function test_other_episodes_are_refused(): void
    {
        $ep = $this->episode();
        $ep->update(['video_url' => 'https://youtu.be/dQw4w9WgXcQ']);

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/episodes/{$ep->id}/playback")
            ->assertStatus(422);
    }
}
