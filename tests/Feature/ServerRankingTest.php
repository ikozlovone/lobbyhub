<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Server;
use App\Models\Vote;
use App\Services\Catalog\ServerRanking;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ranking pass, which had no test of its own until its writes were batched.
 *
 * The formula is checked here too, but the reason this file exists is the write
 * path: it joins the whole batch against a list of literals in one statement,
 * and a batching bug is the kind that only shows up past the batch size.
 */
class ServerRankingTest extends TestCase
{
    use RefreshDatabase;

    /** Comfortably past ServerRanking's write chunk of 200. */
    private const PAST_A_BATCH = 205;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_writes_every_changed_server_across_a_batch_boundary(): void
    {
        Server::factory()
            ->count(self::PAST_A_BATCH)
            ->create(['game_id' => $this->minecraft(), 'uptime_percent' => 90]);

        $written = app(ServerRanking::class)->recompute();

        $this->assertSame(self::PAST_A_BATCH, $written);

        // Uptime alone: 90% of the 100 points a perfect record is worth. Every
        // row must carry it, not just the ones in the first batch.
        $this->assertSame(
            self::PAST_A_BATCH,
            Server::where('rank_score', 90)->where('votes_count', 0)->count(),
        );
    }

    /**
     * Each row is matched by its own id, so two servers in one batch must not
     * come out holding the same score.
     */
    public function test_rows_in_one_batch_keep_their_own_scores(): void
    {
        $quiet = Server::factory()->create(['game_id' => $this->minecraft(), 'uptime_percent' => 10]);
        $solid = Server::factory()->create(['game_id' => $this->minecraft(), 'uptime_percent' => 95]);

        app(ServerRanking::class)->recompute();

        $this->assertSame(10, $quiet->fresh()->rank_score);
        $this->assertSame(95, $solid->fresh()->rank_score);
    }

    /** A row that would be written back unchanged is not written at all. */
    public function test_a_second_pass_writes_nothing(): void
    {
        Server::factory()->count(3)->create(['game_id' => $this->minecraft(), 'uptime_percent' => 50]);

        $ranking = app(ServerRanking::class);

        $this->assertSame(3, $ranking->recompute());
        $this->assertSame(0, $ranking->recompute());
    }

    public function test_votes_uptime_and_promotion_all_land_in_the_score(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->minecraft(),
            'uptime_percent' => 50,
            'promoted_until' => now()->addMonth(),
        ]);

        foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
            Vote::create([
                'server_id' => $server->id,
                'ip_hash' => Vote::hashIp($ip),
                'vote_day' => now()->toDateString(),
            ]);
        }

        app(ServerRanking::class)->recompute();

        // 2 votes × 10, plus half of the 100 uptime points, plus the placement.
        $this->assertSame(20 + 50 + 2000, $server->fresh()->rank_score);
        $this->assertSame(2, $server->fresh()->votes_count);
    }

    /** Votes outside the window still count towards the badge, not the score. */
    public function test_an_old_vote_counts_on_the_badge_but_not_in_the_score(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->minecraft(),
            'uptime_percent' => 0,
        ]);

        Vote::create([
            'server_id' => $server->id,
            'ip_hash' => Vote::hashIp('1.1.1.1'),
            'vote_day' => now()->subDays(400)->toDateString(),
        ]);

        app(ServerRanking::class)->recompute();

        $this->assertSame(0, $server->fresh()->rank_score);
        $this->assertSame(1, $server->fresh()->votes_count);
    }

    private function minecraft(): int
    {
        return Game::where('slug', 'minecraft')->value('id');
    }
}
