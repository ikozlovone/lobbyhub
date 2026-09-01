<?php

namespace Tests\Feature;

use App\Models\Game;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching imported games on in bulk.
 *
 * The catalog imports leave hundreds of games off, and this is how they come
 * on. Which makes the interesting tests the ones about restraint: it changes
 * what the whole site shows, and it should be impossible to do by accident.
 */
class ActivateGamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        Game::query()->update(['is_active' => false, 'steam_stats_synced_at' => null]);
    }

    public function test_it_switches_on_the_games_above_a_player_count(): void
    {
        $this->measure('rust', 88_915);
        $this->measure('dayz', 4_000);

        $this->artisan('games:activate --min-players=10000')->assertSuccessful();

        $this->assertTrue(Game::where('slug', 'rust')->value('is_active'));
        $this->assertFalse(Game::where('slug', 'dayz')->value('is_active'));
    }

    /**
     * A bare run would switch on every imported game there is, which is one
     * word away from three hundred untouched pages in the sitemap.
     */
    public function test_it_refuses_to_act_without_being_told_which_games(): void
    {
        $this->measure('rust', 88_915);

        $this->artisan('games:activate')->assertFailed();

        $this->assertFalse(Game::where('slug', 'rust')->value('is_active'));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->measure('rust', 88_915);

        $this->artisan('games:activate --min-players=1000 --dry-run')->assertSuccessful();

        $this->assertFalse(Game::where('slug', 'rust')->value('is_active'));
    }

    /**
     * A player count only exists once the collector has read one. A game it
     * has never reached is not a game with no players.
     */
    public function test_a_game_that_was_never_measured_is_not_judged_by_its_zero(): void
    {
        Game::where('slug', 'rust')->update(['steam_players_online' => 50_000]);

        $this->artisan('games:activate --min-players=1000')->assertSuccessful();

        $this->assertFalse(Game::where('slug', 'rust')->value('is_active'));
    }

    /** Steam's own top 100 is a shortlist somebody else already curated. */
    public function test_it_can_take_the_games_steam_is_charting(): void
    {
        $this->measure('rust', 88_915, rank: 12);
        $this->measure('dayz', 40_820);

        $this->artisan('games:activate --charted')->assertSuccessful();

        $this->assertTrue(Game::where('slug', 'rust')->value('is_active'));
        $this->assertFalse(Game::where('slug', 'dayz')->value('is_active'));
    }

    /** And the same list, in reverse, for taking a game back off the site. */
    public function test_it_hides_games_too(): void
    {
        $this->measure('rust', 88_915);
        Game::where('slug', 'rust')->update(['is_active' => true]);

        $this->artisan('games:activate --game=rust --hide')->assertSuccessful();

        $this->assertFalse(Game::where('slug', 'rust')->value('is_active'));
    }

    private function measure(string $slug, int $players, ?int $rank = null): void
    {
        Game::where('slug', $slug)->update([
            'steam_players_online' => $players,
            'steam_chart_rank' => $rank,
            'steam_stats_synced_at' => now(),
        ]);
    }
}
