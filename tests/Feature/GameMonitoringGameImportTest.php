<?php

namespace Tests\Feature;

use App\Enums\QueryProtocol;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\GameMonitoringGameImport;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Filling in the games this catalog does not have.
 *
 * Their list is 380 games against this site's 46, and what comes back from it
 * is three fields and a server count — so most of these tests are about what a
 * row is allowed to claim on that evidence.
 */
class GameMonitoringGameImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_adds_a_game_the_catalog_does_not_have(): void
    {
        // A real row from their list, from a game this site does not carry —
        // ARK, which their catalogue leads with, is already seeded here.
        $this->catalogue([
            $this->game('Plains of Pain', 'plains-of-pain', 2218970, servers: 64),
        ]);

        $report = $this->import()->run();

        $this->assertSame(1, $report->created);

        $game = Game::where('slug', 'plains-of-pain')->firstOrFail();

        $this->assertSame('Plains of Pain', $game->name);
        $this->assertSame(2218970, $game->steam_appid);
        // A Steam appid is as good as a declaration of A2S — it is the only
        // one of the three protocols here that fits a game published there.
        $this->assertSame(QueryProtocol::Source, $game->query_protocol);

        // Off, because the row has none of what a game page is made of: no
        // artwork, no description, and a default port that is a guess.
        $this->assertFalse($game->is_active);
    }

    /** Matched on either key, because both are unique on both sides. */
    public function test_a_game_already_here_is_left_alone(): void
    {
        $rust = Game::where('slug', 'rust')->firstOrFail();

        $this->catalogue([
            $this->game('Rust', 'rust', 252490, servers: 25030),
            // Same game, slug we do not use — the appid is what catches it.
            $this->game("Garry's Mod", 'garrys-mod-2', 4000, servers: 23266),
        ]);

        $report = $this->import()->run();

        $this->assertSame(2, $report->existing);
        $this->assertSame(0, $report->created);
        $this->assertSame('Rust', $rust->refresh()->name);
    }

    /**
     * Sixty-three of their games have no servers at all. A game page with
     * nothing on it is the thin content this catalog is careful about
     * everywhere else.
     */
    public function test_a_game_with_no_servers_is_skipped_by_default(): void
    {
        $this->catalogue([
            $this->game('Fortress Obscura', 'fortress-obscura', 999001, servers: 0),
        ]);

        $this->assertSame(1, $this->import()->run()->skipped);
        $this->assertSame(0, Game::where('slug', 'fortress-obscura')->count());

        // And are had anyway when somebody asks for them.
        $this->assertSame(1, $this->import()->run(minServers: 0)->created);
        $this->assertSame(1, Game::where('slug', 'fortress-obscura')->count());
    }

    /**
     * One of their 380 has no Steam appid, and there is nothing to be done
     * with it: their server list is keyed on one, and so is the protocol guess.
     */
    public function test_a_game_without_a_steam_appid_is_skipped(): void
    {
        $this->catalogue([
            ['id' => 1, 'name' => 'Unknown package', 'url' => 'unknown-package', 'steam_id' => null, 'servers' => 12, 'players' => 3],
        ]);

        $this->assertSame(1, $this->import()->run()->skipped);
        $this->assertSame(0, Game::where('slug', 'unknown-package')->count());
    }

    /** New games sort after everything already here, biggest of theirs first. */
    public function test_new_games_sort_after_the_ones_already_here(): void
    {
        $last = (int) Game::max('sort_order');

        $this->catalogue([
            $this->game('Bigger', 'bigger', 999002, servers: 900),
            $this->game('Smaller', 'smaller', 999003, servers: 90),
        ]);

        $this->import()->run();

        $this->assertSame($last + 1, Game::where('slug', 'bigger')->value('sort_order'));
        $this->assertSame($last + 2, Game::where('slug', 'smaller')->value('sort_order'));
    }

    /**
     * Every game gets a `server_states` partition on the way in — GameObserver
     * does it — and without one the first server written for that game takes
     * the insert down with it.
     */
    public function test_an_imported_game_can_hold_servers_immediately(): void
    {
        $this->catalogue([$this->game('Fresh', 'fresh', 999004, servers: 10)]);

        $this->import()->run();

        $game = Game::where('slug', 'fresh')->firstOrFail();
        $server = Server::factory()->create(['game_id' => $game->id]);

        $this->assertNotNull($server->state);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->catalogue([$this->game('Fresh', 'fresh', 999004, servers: 10)]);

        $report = $this->import()->run(write: false);

        $this->assertSame(1, $report->created);
        $this->assertSame(0, Game::where('slug', 'fresh')->count());
    }

    /** `--activate`, for when the review is not wanted. */
    public function test_it_can_create_them_switched_on(): void
    {
        $this->catalogue([$this->game('Fresh', 'fresh', 999004, servers: 10)]);

        $this->import()->run(active: true);

        $this->assertTrue(Game::where('slug', 'fresh')->value('is_active'));
    }

    /** The walk ends on a short page; their `count` is not consulted. */
    public function test_it_reads_pages_until_a_short_one(): void
    {
        $full = [];

        for ($i = 0; $i < 100; $i++) {
            $full[] = $this->game("Game {$i}", "game-{$i}", 900000 + $i, servers: 100);
        }

        Http::fake([
            'api.gamemonitoring.net/*' => Http::sequence()
                ->push(['response' => ['count' => 101, 'items' => $full]])
                ->push(['response' => ['count' => 101, 'items' => [$this->game('Tail', 'tail', 990000, servers: 5)]]]),
        ]);

        $report = $this->import()->run();

        $this->assertSame(2, $report->pages);
        $this->assertSame(101, $report->created);
    }

    private function import(): GameMonitoringGameImport
    {
        return $this->app->make(GameMonitoringGameImport::class);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function catalogue(array $items): void
    {
        Http::fake([
            'api.gamemonitoring.net/*' => Http::response(['response' => ['count' => count($items), 'items' => $items]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function game(string $name, string $url, int $steamId, int $servers): array
    {
        return [
            'id' => 1626086,
            'name' => $name,
            'players' => 20092,
            'servers' => $servers,
            'steam_id' => $steamId,
            'url' => $url,
        ];
    }
}
