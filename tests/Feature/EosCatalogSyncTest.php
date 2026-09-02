<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\EosCatalogSync;
use App\Services\Discovery\EosClient;
use App\Services\Discovery\EosServerSweep;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Turning an EOS matchmaking pull into `server_states` writes.
 *
 * The pass is what makes ASA — a game with no Steam-side registration and no
 * A2S listener — show a live player count in the catalog. The tests are about
 * two things: that a session's shape decodes into the same fields A2S would
 * have carried, and that the write rules (deleted-stays-deleted,
 * created-only-if-enabled, updated-not-duplicated) hold.
 */
class EosCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        $this->game = Game::where('slug', 'ark-survival-ascended')->firstOrFail();

        config([
            // The credentials are only there because the deployment helper
            // refuses a blank triple — Http::fake catches every real request.
            'services.eos.deployments.ark-survival-ascended' => [
                'deployment_id' => 'test-deployment',
                'client_id' => 'test-client',
                'client_secret' => 'test-secret',
            ],
            'services.eos.page_size' => 50,
        ]);

        $this->app->forgetInstance(EosClient::class);
    }

    public function test_it_refreshes_the_state_row_of_a_server_the_catalog_has(): void
    {
        $server = $this->server('5.62.114.92', 7779);

        $this->fakeEos([
            $this->sessionRow('5.62.114.92', 7779, name: 'NA-PVP-Astraeos2575', players: 45, maxPlayers: 70, map: 'Astraeos_WP'),
        ]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(1, $report->distinct);
        $this->assertSame(1, $report->updated);
        $this->assertSame(0, $report->created);

        $state = $server->refresh()->state;
        $this->assertSame(ServerStatus::Online, $state->status);
        $this->assertSame(45, $state->players_online);
        $this->assertSame(70, $state->players_max);
        $this->assertSame('Astraeos_WP', $state->map);
        $this->assertSame('NA-PVP-Astraeos2575', $state->motd);
        $this->assertSame(0, $state->failed_queries_count);
    }

    /**
     * A session for an address the catalog does not hold is dropped by default,
     * the same rule the Steam sync follows and for the same reason.
     */
    public function test_a_new_address_is_skipped_when_creation_is_disabled(): void
    {
        $this->fakeEos([$this->sessionRow('1.2.3.4', 7779)]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(1, $report->skipped);
        $this->assertSame(0, $report->created);
        $this->assertSame(0, Server::where('game_id', $this->game->id)->count());
    }

    /** Turning the flag on is what a first-time backfill uses. */
    public function test_a_new_address_becomes_a_row_when_creation_is_enabled(): void
    {
        config(['monitoring.eos_create_new_servers' => true]);

        $this->fakeEos([$this->sessionRow('1.2.3.4', 7779, name: 'Fresh Server', players: 3, maxPlayers: 10)]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(1, $report->created);

        $server = Server::where('host', '1.2.3.4')->firstOrFail();
        $this->assertSame(7779, $server->port);
        $this->assertSame(7779, $server->query_port);
        $this->assertSame('Fresh Server', $server->name);

        $state = $server->state;
        $this->assertSame(3, $state->players_online);
        $this->assertSame(10, $state->players_max);
    }

    /** A soft-deleted row must not come back through the pass. */
    public function test_a_deleted_server_is_not_resurrected(): void
    {
        config(['monitoring.eos_create_new_servers' => true]);

        $server = $this->server('5.62.114.92', 7779);
        $server->delete();

        $this->fakeEos([$this->sessionRow('5.62.114.92', 7779)]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(0, $report->created);
        $this->assertSame(0, $report->updated);
    }

    /** One machine listed twice is one row, not two. */
    public function test_the_same_address_twice_in_one_page_is_touched_once(): void
    {
        $server = $this->server('5.62.114.92', 7779);

        $this->fakeEos([
            $this->sessionRow('5.62.114.92', 7779, players: 45),
            $this->sessionRow('5.62.114.92', 7779, players: 45),
        ]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(2, $report->found);
        $this->assertSame(1, $report->distinct);
        $this->assertSame(1, $report->updated);
    }

    /** The walk stops on a short page, so a fixed-size fake ends after one call. */
    public function test_it_reads_every_page_until_a_short_one(): void
    {
        config(['services.eos.page_size' => 2]);
        $this->app->forgetInstance(EosClient::class);

        $this->server('1.1.1.1', 7779);
        $this->server('1.1.1.2', 7779);
        $this->server('1.1.1.3', 7779);
        $this->server('1.1.1.4', 7779);
        $this->server('1.1.1.5', 7779);

        Http::fake([
            'api.epicgames.dev/auth/*' => Http::response($this->tokenResponse()),
            'api.epicgames.dev/matchmaking/*' => Http::sequence()
                ->push($this->pagePayload([$this->sessionRow('1.1.1.1', 7779), $this->sessionRow('1.1.1.2', 7779)], totalCount: 5))
                ->push($this->pagePayload([$this->sessionRow('1.1.1.3', 7779), $this->sessionRow('1.1.1.4', 7779)], totalCount: 5))
                ->push($this->pagePayload([$this->sessionRow('1.1.1.5', 7779)], totalCount: 5)),
        ]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(3, $report->pages);
        $this->assertSame(5, $report->found);
        $this->assertSame(5, $report->updated);
    }

    /**
     * `totalCount` from the envelope is the honest stop signal: even if a page
     * comes back at exactly page_size, `offset >= totalCount` ends the walk.
     */
    public function test_total_count_ends_the_walk_before_a_short_page_would(): void
    {
        config(['services.eos.page_size' => 2]);
        $this->app->forgetInstance(EosClient::class);

        $this->server('1.1.1.1', 7779);
        $this->server('1.1.1.2', 7779);

        Http::fake([
            'api.epicgames.dev/auth/*' => Http::response($this->tokenResponse()),
            'api.epicgames.dev/matchmaking/*' => Http::response(
                $this->pagePayload([$this->sessionRow('1.1.1.1', 7779), $this->sessionRow('1.1.1.2', 7779)], totalCount: 2),
            ),
        ]);

        $report = $this->sync()->run($this->game, $this->sweep());

        $this->assertSame(1, $report->pages);
        $this->assertSame(2, $report->found);
    }

    private function sync(): EosCatalogSync
    {
        return $this->app->make(EosCatalogSync::class);
    }

    private function sweep(): EosServerSweep
    {
        return $this->app->make(EosServerSweep::class);
    }

    /** @param  list<array<string, mixed>>  $sessions */
    private function fakeEos(array $sessions): void
    {
        Http::fake([
            'api.epicgames.dev/auth/*' => Http::response($this->tokenResponse()),
            'api.epicgames.dev/matchmaking/*' => Http::response($this->pagePayload($sessions, totalCount: count($sessions))),
        ]);
    }

    /**
     * One matchmaking envelope. `sessions` is the array of session objects and
     * `pagination.totalCount` is Epic's own total for the criteria the client
     * asked for.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return array<string, mixed>
     */
    private function pagePayload(array $sessions, int $totalCount): array
    {
        return [
            'sessions' => $sessions,
            'pagination' => [
                'count' => count($sessions),
                'totalCount' => $totalCount,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function tokenResponse(): array
    {
        return [
            'access_token' => 'fake-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ];
    }

    /**
     * One session, trimmed to the fields DiscoveredEosServer reads plus a
     * little noise so a change to unrelated defaults does not silently affect
     * these tests.
     *
     * @return array<string, mixed>
     */
    private function sessionRow(
        string $ip,
        int $port,
        string $name = 'Test Server',
        int $players = 0,
        int $maxPlayers = 70,
        string $map = 'TheIsland_WP',
    ): array {
        return [
            'id' => 'session-'.substr(md5($ip.$port), 0, 24),
            'totalPlayers' => $players,
            'openPublicPlayers' => max(0, $maxPlayers - $players),
            'started' => true,
            'attributes' => [
                'ADDRESS_s' => $ip,
                'GAMEPORT_l' => $port,
                'MAPNAME_s' => $map,
                'CUSTOMSERVERNAME_s' => $name,
                'SESSIONNAME_s' => $name,
                'BUILDID_s' => '93.12',
                'SERVERUSESBATTLEYE_b' => true,
                'OFFICIALSERVER_s' => '1',
            ],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function server(string $host, int $port, array $attributes = []): Server
    {
        return Server::factory()->create([
            'game_id' => $this->game->id,
            'host' => $host,
            'ip_address' => $host,
            'port' => $port,
            'query_port' => $port,
            ...$attributes,
        ]);
    }
}
