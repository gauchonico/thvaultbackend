<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Play;
use App\Models\Show;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $club;
    private Show $clubShow;
    private Show $ntvShow;
    private Episode $clubEpisode;
    private Episode $ntvEpisode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Partner::create(['name' => 'KCCA FC', 'slug' => 'kcca-fc', 'type' => 'club', 'sport' => 'Football', 'revenue_share_percent' => 50]);
        $this->clubShow = Show::forceCreate(['title' => 'Inside KCCA', 'description' => 'Training', 'year' => 2026, 'rating' => 'PG', 'partner_id' => $this->club->id]);
        $this->ntvShow = Show::forceCreate(['title' => 'NTV Tonight', 'description' => 'News', 'year' => 2026, 'rating' => 'PG']);
        $this->clubEpisode = $this->episode($this->clubShow);
        $this->ntvEpisode = $this->episode($this->ntvShow);
    }

    private function episode(Show $show): Episode
    {
        return Episode::create(['show_id' => $show->id, 'title' => "{$show->title} 1", 'season' => 1, 'episode_number' => 1, 'duration' => '20m']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function subscriber(): User
    {
        $plan = Plan::forceCreate(['name' => 'Standard', 'slug' => 'standard-' . Str::random(4), 'price_ugx' => 24900, 'is_free' => false, 'resolution' => 'Full HD (1080p)', 'max_screens' => 2]);
        $user = User::factory()->create();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()]);

        return $user;
    }

    private function beat(array $overrides = [], ?User $as = null)
    {
        $body = array_merge([
            'session_id' => (string) Str::uuid(), 'device_id' => 'device-a',
            'content_type' => 'episode', 'content_id' => $this->clubEpisode->id, 'seconds' => 10,
        ], $overrides);

        return ($as ? $this->actingAs($as, 'sanctum') : $this)->postJson('/api/plays', $body);
    }

    private function play(array $attributes): Play
    {
        return Play::create(array_merge([
            'session_id' => (string) Str::uuid(), 'device_id' => Str::random(8), 'content_type' => 'episode',
            'content_id' => $this->ntvEpisode->id, 'show_id' => $this->ntvShow->id, 'seconds' => 60,
            'started_at' => now(), 'last_seen_at' => now(),
        ], $attributes));
    }

    public function test_a_guest_play_is_recorded_with_its_show_and_partner(): void
    {
        $this->beat(['session_id' => $id = (string) Str::uuid()])->assertNoContent();

        $play = Play::firstWhere('session_id', $id);
        $this->assertNull($play->user_id);
        $this->assertSame($this->clubShow->id, $play->show_id);
        $this->assertSame($this->club->id, $play->partner_id);
        $this->assertSame(10, $play->seconds);
    }

    public function test_watch_time_only_grows_by_the_real_time_between_heartbeats(): void
    {
        $id = (string) Str::uuid();
        $this->beat(['session_id' => $id, 'seconds' => 10]);

        $this->travel(30)->seconds();
        $this->beat(['session_id' => $id, 'seconds' => 5000])->assertNoContent();
        // 10 + 30 elapsed + 15 slack, not the 5000 claimed
        $this->assertSame(55, Play::firstWhere('session_id', $id)->seconds);

        $this->beat(['session_id' => $id, 'device_id' => 'someone-else', 'seconds' => 60])->assertStatus(409);
        $this->beat(['content_id' => 999999])->assertNotFound();
    }

    public function test_signed_in_subscribers_are_marked_for_revenue_share(): void
    {
        $this->beat(['session_id' => $id = (string) Str::uuid()], $this->subscriber());

        $this->assertTrue(Play::firstWhere('session_id', $id)->is_subscriber);
    }

    public function test_analytics_count_views_after_thirty_seconds_and_people_not_sessions(): void
    {
        $viewer = User::factory()->create();
        $this->play(['user_id' => $viewer->id, 'seconds' => 120]);
        $this->play(['user_id' => $viewer->id, 'seconds' => 45]);      // same person again
        $this->play(['device_id' => 'guest-1', 'seconds' => 300]);
        $this->play(['device_id' => 'guest-2', 'seconds' => 10]);       // too short to be a view
        $channel = Channel::create(['name' => 'KFM', 'type' => 'radio']);
        $this->play(['content_type' => 'channel', 'content_id' => $channel->id, 'show_id' => null, 'seconds' => 3600]);

        $report = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/analytics?days=7')->assertOk();

        $report->assertJsonPath('totals.views', 4)
            ->assertJsonPath('totals.by_type.episode.views', 3)
            ->assertJsonPath('totals.by_type.episode.viewers', 2)
            ->assertJsonPath('totals.by_type.channel.hours', 1)
            ->assertJsonPath('top_shows.0.title', 'NTV Tonight')
            ->assertJsonPath('channels.0.name', 'KFM');
    }

    public function test_analytics_are_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/admin/analytics')->assertForbidden();
    }

    public function test_fans_can_follow_a_club_and_see_its_shows(): void
    {
        $fan = User::factory()->create();

        $this->actingAs($fan, 'sanctum')->postJson('/api/partners/kcca-fc/follow')->assertOk()->assertJsonPath('followers_count', 1);
        $this->actingAs($fan, 'sanctum')->postJson('/api/partners/kcca-fc/follow')->assertJsonPath('followers_count', 1);

        $this->getJson('/api/partners/kcca-fc')->assertOk()
            ->assertJsonPath('partner.followers_count', 1)
            ->assertJsonPath('shows.0.title', 'Inside KCCA')
            ->assertJsonMissingPath('partner.revenue_share_percent');

        $this->actingAs($fan, 'sanctum')->getJson('/api/me/following')->assertJsonPath('shows.0.title', 'Inside KCCA');
        $this->actingAs($fan, 'sanctum')->deleteJson('/api/partners/kcca-fc/follow')->assertJsonPath('followers_count', 0);
    }

    public function test_revenue_share_splits_subscription_revenue_by_subscriber_watch_time(): void
    {
        $subscriber = $this->subscriber();
        $planId = Subscription::first()->plan_id;
        Payment::forceCreate(['reference' => 'SUB-1', 'user_id' => $subscriber->id, 'plan_id' => $planId, 'amount_ugx' => 1_000_000,
            'provider' => 'fake', 'status' => 'successful', 'paid_at' => now()]);
        Payment::forceCreate(['reference' => 'RENT-1', 'user_id' => $subscriber->id, 'plan_id' => null, 'show_id' => $this->clubShow->id,
            'purchase_type' => 'rent', 'amount_ugx' => 20_000, 'provider' => 'fake', 'status' => 'successful', 'paid_at' => now()]);

        // Subscribers watched 3,600s of the club and 6,400s of NTV: the club earned 36% of the pool.
        $this->play(['is_subscriber' => true, 'content_id' => $this->clubEpisode->id, 'show_id' => $this->clubShow->id, 'partner_id' => $this->club->id, 'seconds' => 3600]);
        $this->play(['is_subscriber' => true, 'seconds' => 6400]);
        // Free viewers don't move the split.
        $this->play(['content_id' => $this->clubEpisode->id, 'show_id' => $this->clubShow->id, 'partner_id' => $this->club->id, 'seconds' => 9000]);

        $month = now('Africa/Kampala')->format('Y-m');
        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/revenue-share?month={$month}")->assertOk()
            ->assertJsonPath('subscription_revenue_ugx', 1_000_000)
            ->assertJsonPath('rows.0.watch_share_percent', 36)
            ->assertJsonPath('rows.0.subscription_credit_ugx', 360_000)
            ->assertJsonPath('rows.0.title_sales_ugx', 20_000)
            // (360,000 + 20,000) × 50%
            ->assertJsonPath('rows.0.payout_ugx', 190_000);

        $csv = $this->actingAs($this->admin(), 'sanctum')->get("/api/admin/revenue-share?month={$month}&format=csv");
        $csv->assertOk();
        $this->assertStringContainsString('"KCCA FC",club', $csv->streamedContent());
    }
}
