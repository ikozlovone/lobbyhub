<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Services\Stats\ClickHouseClient;
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

    /**
     * The month-by-month table, and the column that earns it.
     *
     * ClickHouse is faked at the wire here rather than mocked away: the reader
     * speaks HTTP to it, so a fake response exercises the parsing and the
     * arithmetic that turns four months into three comparisons.
     */
    public function test_the_trend_compares_each_month_with_the_one_before(): void
    {
        $this->withClickHouse(
            // Newest first, which is the order the table is read in and the
            // order the query returns.
            [
                ['month' => '2026-09-01', 'players_avg' => '110000', 'players_peak' => '150000', 'hours' => '79200000', 'days' => '12'],
                ['month' => '2026-08-01', 'players_avg' => '100000', 'players_peak' => '140000', 'hours' => '74400000', 'days' => '31'],
                ['month' => '2026-07-01', 'players_avg' => '125000', 'players_peak' => '160000', 'hours' => '90000000', 'days' => '31'],
            ],
            [['first' => '2026-07-01']],
        );
        $this->measure('rust', players: 110_000);

        $months = $this->getJson('/api/games/rust/trend')->assertOk()->json('data.months');

        $this->assertSame(['2026-09', '2026-08', '2026-07'], array_column($months, 'month'));

        // September against August: ten thousand more, a tenth up.
        //
        // assertEquals, not assertSame: these are floats on the PHP side and
        // whole numbers by the time JSON has been through them, and the test
        // is about the arithmetic rather than about which of the two the
        // encoder picked.
        $this->assertEquals(10000, $months[0]['gain']);
        $this->assertEquals(10, $months[0]['gain_percent']);

        // August against July: down, and the sign is the whole point.
        $this->assertEquals(-25000, $months[1]['gain']);
        $this->assertEquals(-20, $months[1]['gain_percent']);

        // July has nothing behind it. Null, not zero — "no change" and
        // "nothing to compare" are different facts.
        $this->assertNull($months[2]['gain']);
        $this->assertNull($months[2]['gain_percent']);

        // The month still running says how much of it was watched, so its
        // average is not read as a full month's.
        $this->assertSame(12, $months[0]['days']);
        $this->assertSame('2026-07-01', $this->getJson('/api/games/rust/trend')->json('data.recording_since'));
    }

    /**
     * Hours played is the one number here nobody publishes — Valve's charts
     * carry a rank, a count and a peak and no playtime at all — so it is our
     * own samples added up, and it rides along with the ranking.
     */
    public function test_hours_played_come_from_our_own_samples(): void
    {
        $this->measure('rust', players: 88_915);
        $this->withClickHouse([['app_id' => '252490', 'hours' => '1904400.5']]);

        $row = $this->getJson('/api/charts')->assertOk()->json('data.0');

        $this->assertSame('rust', $row['slug']);
        $this->assertSame(1_904_401, $row['hours']);
    }

    /** Without somewhere to read them from, the column simply has no number. */
    public function test_hours_are_null_when_clickhouse_is_away(): void
    {
        $this->measure('rust', players: 88_915);

        $this->assertNull($this->getJson('/api/charts')->assertOk()->json('data.0.hours'));
    }

    public function test_the_trend_is_empty_rather_than_broken_without_clickhouse(): void
    {
        $this->measure('rust', players: 88_915);

        $response = $this->getJson('/api/games/rust/trend')->assertOk();

        $this->assertSame([], $response->json('data.months'));
        $this->assertNull($response->json('data.recording_since'));
    }

    public function test_a_game_without_a_steam_appid_has_no_trend(): void
    {
        $this->getJson('/api/games/minecraft/trend')->assertNotFound();
    }

    /** Minecraft has no Steam appid, so there is no such number to serve. */
    public function test_a_game_without_a_steam_appid_has_no_chart(): void
    {
        $this->getJson('/api/games/minecraft/players')->assertNotFound();
    }

    /**
     * Point the reader at a ClickHouse that answers, one response per query in
     * the order the reader makes them.
     *
     * @param  array<int, array<string, mixed>>  ...$responses
     */
    private function withClickHouse(array ...$responses): void
    {
        config(['services.clickhouse.host' => 'clickhouse.test']);
        $this->app->forgetInstance(ClickHouseClient::class);

        $sequence = Http::sequence();

        foreach ($responses as $rows) {
            $sequence->push(['data' => $rows]);
        }

        Http::fake(['clickhouse.test*' => $sequence->whenEmpty(Http::response(['data' => []]))]);
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
