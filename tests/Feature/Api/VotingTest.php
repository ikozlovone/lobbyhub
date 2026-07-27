<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\Vote;
use App\Services\Catalog\ServerRanking;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VotingTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        $this->server = Server::factory()->create([
            'game_id' => Game::where('slug', 'rust')->value('id'),
            'slug' => 'test-server',
            'status' => ServerStatus::Online,
        ]);
    }

    public function test_a_visitor_can_vote_once_a_day(): void
    {
        $this->postJson('/api/servers/test-server/vote', ['nickname' => 'ivan'])
            ->assertCreated()
            ->assertJsonPath('data.voted', true)
            ->assertJsonPath('data.votes_total', 1);

        $this->assertSame(1, Vote::count());
    }

    public function test_the_second_vote_of_the_day_is_refused(): void
    {
        $this->postJson('/api/servers/test-server/vote')->assertCreated();

        $this->postJson('/api/servers/test-server/vote')
            ->assertStatus(429)
            ->assertJsonPath('message', 'You have already voted for this server today.');

        $this->assertSame(1, Vote::count());
    }

    /**
     * The rule is a unique index, not a lookup: two requests arriving together
     * would both pass a "have they voted?" check and both insert.
     */
    public function test_the_daily_limit_is_enforced_by_the_database(): void
    {
        $row = [
            'server_id' => $this->server->id,
            'ip_hash' => Vote::hashIp('1.2.3.4'),
            'vote_day' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Vote::insert([$row]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Vote::insert([$row]);
    }

    public function test_yesterdays_vote_does_not_block_today(): void
    {
        Vote::create([
            'server_id' => $this->server->id,
            'ip_hash' => Vote::hashIp('127.0.0.1'),
            'vote_day' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/servers/test-server/vote')->assertCreated();

        $this->assertSame(2, Vote::count());
    }

    public function test_the_raw_address_is_never_stored(): void
    {
        $this->postJson('/api/servers/test-server/vote')->assertCreated();

        $stored = Vote::firstOrFail()->getAttribute('ip_hash');

        $this->assertSame(64, strlen($stored));
        $this->assertStringNotContainsString('127.0.0.1', $stored);
        // Keyed, not a bare digest — an IP space is small enough to brute force.
        $this->assertNotSame(hash('sha256', '127.0.0.1'), $stored);
    }

    public function test_status_reports_whether_this_visitor_may_vote(): void
    {
        $this->getJson('/api/servers/test-server/vote')
            ->assertOk()
            ->assertJsonPath('data.can_vote', true);

        $this->postJson('/api/servers/test-server/vote')->assertCreated();

        $this->getJson('/api/servers/test-server/vote')
            ->assertOk()
            ->assertJsonPath('data.can_vote', false);
    }

    public function test_an_owner_claims_rewards_with_the_server_token(): void
    {
        $this->server->update(['claim_token' => 'token-abc']);
        $this->postJson('/api/servers/test-server/vote', ['nickname' => 'ivan'])->assertCreated();

        $this->postJson('/api/servers/test-server/votes/claim', [
            'nickname' => 'ivan',
            'token' => 'token-abc',
        ])->assertOk()->assertJsonPath('data.rewards', 1);

        // A reward cannot be collected twice.
        $this->postJson('/api/servers/test-server/votes/claim', [
            'nickname' => 'ivan',
            'token' => 'token-abc',
        ])->assertOk()->assertJsonPath('data.rewards', 0);
    }

    public function test_claiming_without_the_right_token_is_refused(): void
    {
        $this->server->update(['claim_token' => 'token-abc']);
        $this->postJson('/api/servers/test-server/vote', ['nickname' => 'ivan'])->assertCreated();

        $this->postJson('/api/servers/test-server/votes/claim', [
            'nickname' => 'ivan',
            'token' => 'wrong',
        ])->assertForbidden();

        $this->assertNull(Vote::firstOrFail()->rewarded_at);
    }

    public function test_a_server_without_a_token_cannot_be_claimed_against(): void
    {
        $this->postJson('/api/servers/test-server/vote', ['nickname' => 'ivan'])->assertCreated();

        $this->postJson('/api/servers/test-server/votes/claim', [
            'nickname' => 'ivan',
            'token' => '',
        ])->assertStatus(422);
    }

    public function test_points_are_made_of_votes_activity_uptime_and_placement(): void
    {
        $ranking = app(ServerRanking::class);

        $this->assertSame(100, $ranking->points(recentVotes: 10, averagePlayers: 0, uptimePercent: null, promoted: false));
        $this->assertSame(200, $ranking->points(recentVotes: 0, averagePlayers: 100, uptimePercent: null, promoted: false));
        $this->assertSame(100, $ranking->points(recentVotes: 0, averagePlayers: 0, uptimePercent: 100, promoted: false));
        $this->assertSame(2000, $ranking->points(recentVotes: 0, averagePlayers: 0, uptimePercent: null, promoted: true));
    }

    public function test_only_votes_inside_the_window_count(): void
    {
        $old = now()->subDays((int) config('ranking.vote_window_days') + 5)->toDateString();

        Vote::create(['server_id' => $this->server->id, 'ip_hash' => str_repeat('a', 64), 'vote_day' => $old]);
        Vote::create(['server_id' => $this->server->id, 'ip_hash' => str_repeat('b', 64), 'vote_day' => now()->toDateString()]);

        // Isolate the vote contribution from the factory's uptime.
        $this->server->update(['uptime_percent' => null]);

        app(ServerRanking::class)->recompute();
        $this->server->refresh();

        // One recent vote scores; the old one still shows in the all-time total.
        $this->assertSame((int) config('ranking.vote_points'), $this->server->rank_score);
        $this->assertSame(2, $this->server->votes_count);
    }

    public function test_standing_reports_position_and_the_gap_to_the_leader(): void
    {
        $leader = Server::factory()->create([
            'game_id' => $this->server->game_id,
            'status' => ServerStatus::Online,
            'rank_score' => 500,
        ]);
        $this->server->update(['rank_score' => 100]);

        $standing = app(ServerRanking::class)->standing($this->server);

        $this->assertSame(2, $standing['position']);
        $this->assertSame(2, $standing['total']);
        $this->assertSame(100, $standing['points']);
        $this->assertSame(500, $standing['leader_points']);
        $this->assertSame(500, app(ServerRanking::class)->standing($leader)['leader_points']);
    }

    public function test_voting_for_an_unlisted_server_is_not_possible(): void
    {
        $this->server->update(['is_active' => false]);

        $this->postJson('/api/servers/test-server/vote')->assertNotFound();
    }
}
