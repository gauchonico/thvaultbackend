<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeAiredAtTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin, 'sanctum');

        $this->show = Show::forceCreate(['title' => 'Show', 'description' => 'Desc', 'year' => 2026, 'rating' => 'PG']);
    }

    private function makeEpisode(int $number, ?string $airedAt = null): Episode
    {
        return Episode::create([
            'show_id' => $this->show->id, 'title' => "Ep {$number}", 'season' => 1,
            'episode_number' => $number, 'duration' => '21m', 'aired_at' => $airedAt,
        ]);
    }

    public function test_admin_can_set_and_clear_aired_at(): void
    {
        $this->postJson("/api/admin/episodes/{$this->show->id}", [
            'title' => 'Ep 1', 'season' => 1, 'episode_number' => 1, 'duration' => '21m', 'aired_at' => '2026-02-17',
        ])->assertCreated()->assertJsonPath('aired_at', '2026-02-17');

        $ep = Episode::first();
        $this->putJson("/api/admin/episodes/{$ep->id}", ['aired_at' => null])->assertOk();
        $this->assertNull($ep->fresh()->aired_at);
    }

    public function test_backfill_fills_only_missing_dates_in_broadcast_order(): void
    {
        $kept = $this->makeEpisode(1, '2020-01-01');
        $second = $this->makeEpisode(2);
        $third = $this->makeEpisode(3);

        $this->artisan('episodes:backfill-aired-dates')->assertSuccessful();

        $this->assertSame('2020-01-01', $kept->fresh()->aired_at->toDateString());
        $a = $second->fresh()->aired_at;
        $b = $third->fresh()->aired_at;
        $this->assertNotNull($a);
        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($b->lessThanOrEqualTo(today()));
        $this->assertTrue($a->greaterThanOrEqualTo(today()->subYears(3)));
    }

    public function test_backfill_dry_run_changes_nothing(): void
    {
        $ep = $this->makeEpisode(1);
        $this->artisan('episodes:backfill-aired-dates --dry-run')->assertSuccessful();
        $this->assertNull($ep->fresh()->aired_at);
    }
}
