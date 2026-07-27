<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\DiscoveredServer;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.steam.key' => 'test-key']);
        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_imports_servers_as_unverified_candidates(): void
    {
        Http::fake([
            '*GetServerList*' => Http::response(['response' => ['servers' => [
                $this->apiServer(addr: '1.2.3.4:28017', gameport: 28015, players: 90, name: 'Busy Rust'),
            ]]]),
        ]);

        $this->artisan('discovery:steam', ['--game' => 'rust'])->assertSuccessful();

        $server = Server::firstOrFail();

        $this->assertSame('1.2.3.4', $server->host);
        // The port players connect to is the identity; the query port is separate.
        $this->assertSame(28015, $server->port);
        $this->assertSame(28017, $server->query_port);
        $this->assertSame(28015, $server->game_port);

        // Second-hand data is not published: our own monitor has to confirm it.
        $this->assertSame(ServerStatus::Unknown, $server->status);
        $this->assertSame(0, $server->players_online);
        $this->assertNotNull($server->next_query_at);
    }

    public function test_an_unverified_server_stays_out_of_the_catalog(): void
    {
        Http::fake(['*GetServerList*' => Http::response(['response' => ['servers' => [
            $this->apiServer(addr: '1.2.3.4:28017', gameport: 28015, players: 90, name: 'Unverified'),
        ]]])]);

        $this->artisan('discovery:steam', ['--game' => 'rust'])->assertSuccessful();

        $listed = $this->getJson('/api/games/rust/servers')->assertOk()->json('data');
        $this->assertCount(0, $listed);

        // It appears once monitoring has reached it.
        Server::firstOrFail()->update(['status' => ServerStatus::Online, 'players_online' => 90]);

        $listed = $this->getJson('/api/games/rust/servers')->assertOk()->json('data');
        $this->assertCount(1, $listed);
    }

    public function test_it_trusts_the_tag_counts_over_the_api_field(): void
    {
        // The API's own `players` disagrees with the cp tag on ~17% of servers.
        $found = DiscoveredServer::fromApi($this->apiServer(
            addr: '1.2.3.4:28017',
            gameport: 28015,
            players: 74,
            name: 'Rust',
            gametype: 'mp300,cp72,qp5,born1785121428,gmrust',
        ));

        $this->assertSame(72, $found->playersOnline);
        $this->assertSame(300, $found->playersMax);
        $this->assertSame(5, $found->playersQueued);
        $this->assertNotNull($found->wipedAt);
    }

    public function test_re_running_refreshes_ports_without_touching_the_public_slug(): void
    {
        $game = Game::where('slug', 'rust')->firstOrFail();
        $existing = Server::factory()->create([
            'game_id' => $game->id,
            'host' => '1.2.3.4',
            'port' => 28015,
            'slug' => 'owner-chosen-slug',
            'name' => 'Owner Chosen Name',
            'query_port' => null,
        ]);

        Http::fake(['*GetServerList*' => Http::response(['response' => ['servers' => [
            $this->apiServer(addr: '1.2.3.4:28017', gameport: 28015, players: 10, name: 'Whatever Steam Says'),
        ]]])]);

        $this->artisan('discovery:steam', ['--game' => 'rust'])->assertSuccessful();

        $existing->refresh();

        $this->assertSame('owner-chosen-slug', $existing->slug);
        $this->assertSame('Owner Chosen Name', $existing->name);
        $this->assertSame(28017, $existing->query_port);
        $this->assertSame(1, Server::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Http::fake(['*GetServerList*' => Http::response(['response' => ['servers' => [
            $this->apiServer(addr: '1.2.3.4:28017', gameport: 28015, players: 5, name: 'Rust'),
        ]]])]);

        $this->artisan('discovery:steam', ['--game' => 'rust', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Server::count());
    }

    public function test_it_keeps_the_busiest_servers_when_cutting_to_top(): void
    {
        Http::fake(['*GetServerList*' => Http::response(['response' => ['servers' => [
            $this->apiServer(addr: '1.1.1.1:28017', gameport: 28015, players: 5, name: 'Quiet'),
            $this->apiServer(addr: '2.2.2.2:28017', gameport: 28015, players: 250, name: 'Busy'),
            $this->apiServer(addr: '3.3.3.3:28017', gameport: 28015, players: 40, name: 'Medium'),
        ]]])]);

        $this->artisan('discovery:steam', ['--game' => 'rust', '--top' => 2])->assertSuccessful();

        $this->assertSame(['Busy', 'Medium'], Server::orderByDesc('players_max')->pluck('name')->all());
    }

    public function test_it_skips_rows_with_an_unusable_address(): void
    {
        $this->assertNull(DiscoveredServer::fromApi(['addr' => 'not-an-address']));
        $this->assertNull(DiscoveredServer::fromApi(['addr' => 'example.com:28015']));
        $this->assertNull(DiscoveredServer::fromApi([]));
    }

    public function test_it_fails_loudly_without_an_api_key(): void
    {
        config(['services.steam.key' => '']);

        $this->artisan('discovery:steam', ['--game' => 'rust'])
            ->expectsOutputToContain('STEAM_API_KEY is not set')
            ->assertSuccessful();

        $this->assertSame(0, Server::count());
    }

    private function apiServer(
        string $addr,
        int $gameport,
        int $players,
        string $name,
        string $gametype = 'gmrust',
    ): array {
        return [
            'addr' => $addr,
            'gameport' => $gameport,
            'name' => $name,
            'appid' => 252490,
            'gamedir' => 'rust',
            'version' => '2631',
            'players' => $players,
            'max_players' => 100,
            'map' => 'Procedural Map',
            'gametype' => $gametype,
        ];
    }
}
