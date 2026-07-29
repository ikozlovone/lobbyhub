<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_lists_games_with_their_counters(): void
    {
        $this->minecraftServer(['players_online' => 120]);
        $this->minecraftServer(['players_online' => 30]);
        $this->artisan('counters:refresh');

        $response = $this->getJson('/api/games')->assertOk();

        $minecraft = collect($response->json('data'))->firstWhere('slug', 'minecraft');

        $this->assertSame(2, $minecraft['counters']['servers']);
        $this->assertSame(150, $minecraft['counters']['players_online']);
        $this->assertTrue($minecraft['has_versions']);
    }

    public function test_a_game_page_carries_facets_with_counts(): void
    {
        $server = $this->minecraftServer(['country_id' => Country::where('code', 'DE')->value('id')]);
        $mode = Game::where('slug', 'minecraft')->firstOrFail()->modes()->where('slug', 'survival')->firstOrFail();
        $server->modes()->attach($mode);

        $this->artisan('counters:refresh');

        $response = $this->getJson('/api/games/minecraft')->assertOk();

        $this->assertSame('survival', $response->json('data.facets.modes.0.slug'));
        $this->assertSame(1, $response->json('data.facets.modes.0.servers_count'));
        // Country counts are per game, not the global counter on `countries`.
        $this->assertSame('germany', $response->json('data.facets.countries.0.slug'));
        $this->assertSame(1, $response->json('data.facets.countries.0.servers_count'));
    }

    public function test_an_inactive_game_is_not_reachable(): void
    {
        Game::where('slug', 'fivem')->update(['is_active' => false]);

        $this->getJson('/api/games/fivem')->assertNotFound();
    }

    public function test_servers_are_listed_busiest_first_with_promoted_on_top(): void
    {
        $this->minecraftServer(['slug' => 'busy', 'players_online' => 500]);
        $this->minecraftServer(['slug' => 'quiet', 'players_online' => 5]);
        $this->minecraftServer(['slug' => 'paid', 'players_online' => 1, 'promoted_until' => now()->addMonth()]);

        $slugs = collect($this->getJson('/api/games/minecraft/servers')->assertOk()->json('data'))
            ->pluck('slug')
            ->all();

        $this->assertSame(['paid', 'busy', 'quiet'], $slugs);
    }

    public function test_it_filters_by_mode_version_and_country(): void
    {
        $minecraft = Game::where('slug', 'minecraft')->firstOrFail();
        $survival = $minecraft->modes()->where('slug', 'survival')->firstOrFail();
        $latest = $minecraft->versions()->where('slug', '1-21')->firstOrFail();
        $germany = Country::where('code', 'DE')->firstOrFail();

        $match = $this->minecraftServer([
            'slug' => 'match',
            'game_version_id' => $latest->id,
            'country_id' => $germany->id,
        ]);
        $match->modes()->attach($survival);

        $this->minecraftServer(['slug' => 'other']);

        $response = $this->getJson('/api/games/minecraft/servers?mode=survival&version=1-21&country=germany')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('match', $response->json('data.0.slug'));
    }

    public function test_status_buckets_split_capacity_from_availability(): void
    {
        $this->minecraftServer(['slug' => 'busy', 'players_online' => 40, 'players_max' => 100]);
        $this->minecraftServer(['slug' => 'packed', 'players_online' => 100, 'players_max' => 100]);
        $this->minecraftServer(['slug' => 'idle', 'players_online' => 0, 'players_max' => 100]);
        // Offline servers hold no players, so they must not land in "empty".
        $this->minecraftServer([
            'slug' => 'down',
            'status' => ServerStatus::Offline,
            'players_online' => 0,
            'players_max' => 100,
        ]);

        $bucket = fn (string $status) => collect(
            $this->getJson("/api/games/minecraft/servers?status={$status}")->assertOk()->json('data')
        )->pluck('slug')->sort()->values()->all();

        $this->assertSame(['busy', 'idle', 'packed'], $bucket('online'));
        $this->assertSame(['busy', 'packed'], $bucket('players'));
        $this->assertSame(['packed'], $bucket('full'));
        $this->assertSame(['idle'], $bucket('empty'));
        $this->assertSame(['down'], $bucket('offline'));
    }

    public function test_a_server_reporting_no_capacity_is_never_counted_as_full(): void
    {
        // A query that came back without a slot count would otherwise satisfy
        // players_online >= players_max at zero and read as a full server.
        $this->minecraftServer(['slug' => 'unknown-capacity', 'players_online' => 0, 'players_max' => 0]);

        $this->assertSame([], $this->getJson('/api/games/minecraft/servers?status=full')->json('data'));
    }

    public function test_status_facets_count_the_whole_game_not_the_current_filter(): void
    {
        $this->minecraftServer(['players_online' => 40, 'players_max' => 100]);
        $this->minecraftServer(['players_online' => 0, 'players_max' => 100]);
        $this->minecraftServer(['status' => ServerStatus::Offline]);
        // Never queried successfully, so it is in no bucket and in no listing.
        $this->minecraftServer(['status' => ServerStatus::Unknown]);

        $counts = collect($this->getJson('/api/games/minecraft')->assertOk()->json('data.facets.statuses'))
            ->pluck('servers_count', 'slug');

        $this->assertSame(2, $counts['online']);
        $this->assertSame(1, $counts['players']);
        $this->assertSame(1, $counts['empty']);
        $this->assertSame(1, $counts['offline']);
        $this->assertSame(0, $counts['full']);
    }

    public function test_maps_are_offered_as_a_facet_and_filtered_by_their_reported_name(): void
    {
        $this->minecraftServer(['slug' => 'a', 'map' => 'Skyblock']);
        $this->minecraftServer(['slug' => 'b', 'map' => 'Skyblock']);
        $this->minecraftServer(['slug' => 'c', 'map' => 'Procedural Map']);
        $this->minecraftServer(['slug' => 'd', 'map' => null]);

        $maps = $this->getJson('/api/games/minecraft')->assertOk()->json('data.facets.maps');

        $this->assertSame('Skyblock', $maps[0]['name']);
        $this->assertSame(2, $maps[0]['servers_count']);
        // The facet's slug is the value the filter takes.
        $this->assertSame('Skyblock', $maps[0]['slug']);

        $filtered = $this->getJson('/api/games/minecraft/servers?map=Procedural+Map')->assertOk();

        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('c', $filtered->json('data.0.slug'));
    }

    public function test_it_searches_a_listing_by_name_regardless_of_case(): void
    {
        // Postgres compares LIKE case-sensitively; sqlite does not. Searching in
        // lower case for a name that is capitalised is the case that told them
        // apart, so it is the case worth asserting.
        $this->minecraftServer(['slug' => 'hit', 'name' => 'Skyblock Paradise']);
        $this->minecraftServer(['slug' => 'miss', 'name' => 'Anarchy Wasteland']);

        $response = $this->getJson('/api/games/minecraft/servers?q=paradise')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('hit', $response->json('data.0.slug'));

        $byHost = $this->getJson('/api/games/minecraft/servers?q='.urlencode('SKYBLOCK'))->assertOk();

        $this->assertSame('hit', $byHost->json('data.0.slug'));
    }

    public function test_it_rejects_an_unknown_status(): void
    {
        $this->getJson('/api/games/minecraft/servers?status=whatever')->assertStatus(422);
    }

    public function test_it_rejects_an_unknown_sort(): void
    {
        $this->getJson('/api/games/minecraft/servers?sort=whatever')
            ->assertStatus(422);
    }

    public function test_it_refuses_to_paginate_beyond_the_cap(): void
    {
        // Deep pages are slow and dilute the search index.
        $this->getJson('/api/games/minecraft/servers?page=101')->assertStatus(422);
    }

    public function test_a_server_page_reports_the_address_the_server_gave_us(): void
    {
        $server = $this->minecraftServer([
            'slug' => 'hypixel',
            'host' => 'mc.hypixel.net',
            'port' => 25565,
            'game_port' => 25566,
        ]);

        $response = $this->getJson("/api/servers/{$server->slug}")->assertOk();

        $this->assertSame('mc.hypixel.net:25566', $response->json('data.address'));
        $this->assertSame(25566, $response->json('data.port'));
        $this->assertSame('minecraft', $response->json('data.game.slug'));
    }

    public function test_a_server_page_carries_the_facts_the_information_panel_shows(): void
    {
        $server = $this->minecraftServer([
            'slug' => 'facts',
            'name' => 'РОССИЯ X2 | ДЛЯ НОВИЧКОВ',
            'bots' => 2,
            'vac_enabled' => true,
            'last_online_at' => now()->subHour(),
            'last_offline_at' => now()->subDays(2),
        ]);

        $response = $this->getJson("/api/servers/{$server->slug}")->assertOk();

        $this->assertSame(2, $response->json('data.bots'));
        $this->assertTrue($response->json('data.vac'));
        $this->assertNotNull($response->json('data.last_offline_at'));
        // Inferred from the name; no protocol reports it. See ServerLanguage.
        $this->assertSame('Russian', $response->json('data.language.name'));
    }

    public function test_a_server_that_reveals_no_language_reports_none(): void
    {
        $server = $this->minecraftServer(['slug' => 'quiet', 'name' => 'Rustafied EU Friday']);

        $this->assertNull($this->getJson("/api/servers/{$server->slug}")->json('data.language'));
    }

    public function test_the_live_endpoint_returns_only_moving_numbers(): void
    {
        $this->minecraftServer(['slug' => 'a', 'players_online' => 10]);
        $this->minecraftServer(['slug' => 'b', 'players_online' => 20]);
        $this->minecraftServer(['slug' => 'c']);

        $response = $this->getJson('/api/servers/live?slugs=a,b')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(['slug', 'status', 'players', 'max_players', 'queued', 'checked_at'],
            array_keys($response->json('data.0')));
    }

    public function test_history_reads_raw_samples_for_short_ranges(): void
    {
        $server = $this->minecraftServer();

        foreach (range(0, 11) as $i) {
            ServerStat::insert([[
                'server_id' => $server->id,
                'recorded_at' => now()->subHours(12)->addHours($i),
                'is_online' => true,
                'players_online' => $i * 10,
                'players_max' => 200,
            ]]);
        }

        $response = $this->getJson("/api/servers/{$server->slug}/history?range=24h")->assertOk();

        $this->assertSame('raw', $response->json('data.source'));
        $this->assertCount(12, $response->json('data.points'));
        $this->assertSame(0, $response->json('data.points.0.players'));
    }

    public function test_history_falls_back_to_daily_rollups_for_long_ranges(): void
    {
        $server = $this->minecraftServer();

        ServerStat::insert([[
            'server_id' => $server->id,
            'recorded_at' => now()->subDay()->startOfDay()->addHour(),
            'is_online' => true,
            'players_online' => 40,
            'players_max' => 200,
        ]]);
        $this->artisan('stats:rollup', ['--date' => now()->subDay()->toDateString(), '--prune-days' => 0]);

        $response = $this->getJson("/api/servers/{$server->slug}/history?range=30d")->assertOk();

        $this->assertSame('daily', $response->json('data.source'));
        $this->assertSame(40, $response->json('data.points.0.players'));
        $this->assertArrayHasKey('uptime', $response->json('data.points.0'));
    }

    public function test_history_downsamples_instead_of_returning_thousands_of_points(): void
    {
        $server = $this->minecraftServer();

        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'server_id' => $server->id,
                'recorded_at' => now()->subDay()->addMinutes($i),
                'is_online' => true,
                'players_online' => 100,
                'players_max' => 200,
            ];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            ServerStat::insert($chunk);
        }

        $points = $this->getJson("/api/servers/{$server->slug}/history?range=24h")->json('data.points');

        $this->assertLessThanOrEqual(240, count($points));
        $this->assertSame(100, $points[0]['players']);
    }

    public function test_search_matches_game_aliases_and_server_names(): void
    {
        $this->minecraftServer(['slug' => 'hypixel', 'name' => 'Hypixel Network']);

        // "mc" is an alias of Minecraft, not part of its name.
        $byAlias = $this->getJson('/api/search?q=mc')->assertOk();
        $this->assertSame('minecraft', $byAlias->json('data.games.0.slug'));

        $byName = $this->getJson('/api/search?q=Hypixel')->assertOk();
        $this->assertSame('hypixel', $byName->json('data.servers.0.slug'));
    }

    public function test_search_needs_at_least_two_characters(): void
    {
        $this->getJson('/api/search?q=m')->assertStatus(422);
    }

    private function minecraftServer(array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
