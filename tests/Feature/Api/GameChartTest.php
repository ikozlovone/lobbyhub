<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The player-count chart.
 *
 * A ranking, which makes the interesting cases the ones about what is allowed
 * into it: a row that has never been measured is not a game with no players,
 * and putting it at the bottom would be claiming a position it has not earned.
 */
class GameChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        // The seeder leaves every game unmeasured, which is the state before
        // the collector's first tick — and the state most of these assert on.
        Game::query()->update(['steam_stats_synced_at' => null]);
    }

    public function test_it_ranks_games_by_players_in_the_game(): void
    {
        $this->measure('rust', players: 88_915, peak: 106_877, rank: 12);
        $this->measure('counter-strike-2', players: 1_205_853, peak: 1_225_505, rank: 1);
        $this->measure('dayz', players: 40_820, peak: 52_408, rank: null);

        $response = $this->getJson('/api/charts')->assertOk();

        $this->assertSame(
            ['counter-strike-2', 'rust', 'dayz'],
            array_column($response->json('data'), 'slug'),
        );
        $this->assertSame([1, 2, 3], array_column($response->json('data'), 'position'));

        $top = $response->json('data.0');
        $this->assertSame(1_205_853, $top['players']);
        $this->assertSame(1_225_505, $top['peak']);
        // Its position in Steam's own top 100, which is not its position here:
        // this chart ranks only the games this catalog carries.
        $this->assertSame(1, $top['steam_rank']);
        $this->assertNull($response->json('data.2.steam_rank'));
    }

    /**
     * A game the collector has not reached has no number, and no number is not
     * zero — it would sit at the bottom of the ranking looking abandoned.
     */
    public function test_a_game_that_has_never_been_measured_is_left_out(): void
    {
        $this->measure('rust', players: 88_915);

        $response = $this->getJson('/api/charts')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('rust', $response->json('data.0.slug'));
    }

    /** A game switched off in the catalog is off everywhere, this included. */
    public function test_an_inactive_game_is_left_out(): void
    {
        $this->measure('rust', players: 88_915);
        Game::where('slug', 'rust')->update(['is_active' => false]);

        $this->assertCount(0, $this->getJson('/api/charts')->assertOk()->json('data'));
    }

    public function test_the_totals_describe_the_chart(): void
    {
        $this->measure('rust', players: 88_915, rank: 12);
        $this->measure('counter-strike-2', players: 1_205_853, rank: 1);
        $this->measure('dayz', players: 40_820, rank: null);

        $meta = $this->getJson('/api/charts')->assertOk()->json('meta');

        $this->assertSame(3, $meta['games']);
        $this->assertSame(1_335_588, $meta['players']);
        // How many of ours Steam itself is charting, which is the sentence the
        // page prints beside the total.
        $this->assertSame(2, $meta['charted']);
        $this->assertNotNull($meta['synced_at']);
    }

    /**
     * Both numbers travel together on a game, because the page that shows one
     * has to be able to say how it differs from the other.
     */
    public function test_a_game_carries_both_counts(): void
    {
        $this->measure('rust', players: 88_915, peak: 106_877, rank: 12);

        $game = $this->getJson('/api/games/rust')->assertOk()->json('data');

        $this->assertSame(88_915, $game['steam']['players_online']);
        $this->assertSame(106_877, $game['steam']['players_peak']);
        $this->assertSame(12, $game['steam']['chart_rank']);
        $this->assertNotNull($game['steam']['synced_at']);
        // The other one: players our monitor found on servers.
        $this->assertArrayHasKey('players_online', $game['counters']);
    }

    /**
     * The history is read from ClickHouse, which is not configured here — and
     * an analytics box being away must cost a chart rather than a page.
     */
    public function test_history_answers_empty_when_there_is_nowhere_to_read_from(): void
    {
        Http::fake();
        $this->measure('rust', players: 88_915);

        $response = $this->getJson('/api/games/rust/players?range=7d')->assertOk();

        $this->assertSame('7d', $response->json('data.range'));
        $this->assertSame([], $response->json('data.points'));
        $this->assertNull($response->json('data.recording_since'));
    }

    /** Minecraft has no Steam appid, so there is no such number to serve. */
    public function test_a_game_without_a_steam_appid_has_no_chart(): void
    {
        $this->getJson('/api/games/minecraft/players')->assertNotFound();
    }

    private function measure(string $slug, int $players, int $peak = 0, ?int $rank = null): void
    {
        Game::where('slug', $slug)->update([
            'steam_players_online' => $players,
            'steam_players_peak' => $peak,
            'steam_chart_rank' => $rank,
            'steam_stats_synced_at' => now(),
        ]);
    }
}
