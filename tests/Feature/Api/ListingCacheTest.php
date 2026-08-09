<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\CatalogCounters;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The listing's own cache, and the two things that empty it.
 *
 * Every test here writes to the database behind the endpoint's back, which is
 * the only way to tell a cached answer from a fresh one: nothing in the payload
 * says which it was.
 */
class ListingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_an_identical_listing_is_answered_from_the_cache(): void
    {
        $this->server('minecraft', ['slug' => 'one', 'name' => 'Before']);

        $this->getJson('/api/games/minecraft/servers')->assertOk();

        Server::where('slug', 'one')->update(['name' => 'After']);

        $this->assertSame(
            'Before',
            $this->getJson('/api/games/minecraft/servers')->assertOk()->json('data.0.name'),
        );
    }

    public function test_a_game_gaining_a_server_drops_its_listing(): void
    {
        $this->server('minecraft', ['slug' => 'one']);
        $this->settleCounters();

        $this->getJson('/api/games/minecraft/servers')->assertOk();

        $this->server('minecraft', ['slug' => 'two']);
        app(CatalogCounters::class)->refresh();

        $this->assertCount(
            2,
            $this->getJson('/api/games/minecraft/servers')->assertOk()->json('data'),
        );
    }

    /**
     * Discovery moves one game's count most minutes. If that emptied every
     * game's listing, the cache would never survive its first minute.
     */
    public function test_one_games_change_leaves_another_games_listing_alone(): void
    {
        $this->server('minecraft', ['slug' => 'mc']);
        $this->server('rust', ['slug' => 'rust-one', 'name' => 'Before']);
        $this->settleCounters();

        $this->getJson('/api/games/minecraft/servers')->assertOk();
        $this->getJson('/api/games/rust/servers')->assertOk();

        // Rust changes only behind the cache's back; Minecraft genuinely grows.
        Server::where('slug', 'rust-one')->update(['name' => 'After']);
        $this->server('minecraft', ['slug' => 'mc-two']);

        app(CatalogCounters::class)->refresh();

        $this->assertCount(
            2,
            $this->getJson('/api/games/minecraft/servers')->assertOk()->json('data'),
        );
        $this->assertSame(
            'Before',
            $this->getJson('/api/games/rust/servers')->assertOk()->json('data.0.name'),
        );
    }

    /** The cross-game listing spans every game, so any of them changing drops it. */
    public function test_the_catalog_wide_listing_is_dropped_too(): void
    {
        $this->server('minecraft', ['slug' => 'one']);
        $this->settleCounters();

        $this->getJson('/api/servers')->assertOk();

        $this->server('rust', ['slug' => 'two']);
        app(CatalogCounters::class)->refresh();

        $this->assertCount(2, $this->getJson('/api/servers')->assertOk()->json('data'));
    }

    /** Free text has no bounded keyspace, so it is left out of the cache. */
    public function test_a_search_is_not_cached(): void
    {
        $this->server('minecraft', ['slug' => 'one', 'name' => 'Alpha One', 'map' => 'before']);

        $this->getJson('/api/games/minecraft/servers?q=alpha')->assertOk();

        Server::where('slug', 'one')->update(['map' => 'after']);

        $this->assertSame(
            'after',
            $this->getJson('/api/games/minecraft/servers?q=alpha')->assertOk()->json('data.0.map'),
        );
    }

    /**
     * `page` is a `sometimes` rule, so it is absent from the validated filters
     * on page one. Keyed off those alone, both pages would be one entry and the
     * second one asked for would be served the first one's rows.
     */
    public function test_the_second_page_is_not_served_the_first_ones_rows(): void
    {
        foreach ([500, 400, 300, 200, 100] as $index => $players) {
            $this->server('minecraft', ['slug' => "s-{$index}", 'players_online' => $players]);
        }

        $first = $this->getJson('/api/games/minecraft/servers?per_page=3')->assertOk();
        $second = $this->getJson('/api/games/minecraft/servers?per_page=3&page=2')->assertOk();

        $this->assertSame(['s-0', 's-1', 's-2'], array_column($first->json('data'), 'slug'));
        $this->assertSame(['s-3', 's-4'], array_column($second->json('data'), 'slug'));
    }

    /** Two filters are two entries, not one shared by whichever asked first. */
    public function test_filters_do_not_share_an_entry(): void
    {
        $this->server('minecraft', ['slug' => 'up', 'players_online' => 5]);
        $this->server('minecraft', [
            'slug' => 'down',
            'status' => ServerStatus::Offline,
            'players_online' => 0,
        ]);

        $all = $this->getJson('/api/games/minecraft/servers')->assertOk()->json('data');
        $online = $this->getJson('/api/games/minecraft/servers?status=online')->assertOk()->json('data');

        $this->assertCount(2, $all);
        $this->assertSame(['up'], array_column($online, 'slug'));
    }

    /**
     * Bring `games.servers_count` up to date before the cache is warmed.
     *
     * Without this the first refresh under test sees every game move from the
     * seeder's zero and reports all of them as changed, which would flush
     * everything and let a test that is checking the opposite still pass.
     */
    private function settleCounters(): void
    {
        app(CatalogCounters::class)->refresh();
    }

    private function server(string $game, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', $game)->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
