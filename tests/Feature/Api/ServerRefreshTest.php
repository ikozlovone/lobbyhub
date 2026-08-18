<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Monitoring\Contracts\ProvidesServerDetails;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The refresh button behind the Server information panel.
 *
 * Every test here stands in for the driver: the point is what the endpoint does
 * with an answer, and reaching a real machine from a test suite is not a thing
 * to arrange even once.
 */
class ServerRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        $this->server = Server::factory()->create([
            'game_id' => Game::where('slug', 'rust')->value('id'),
            'slug' => 'refresh-me',
            'status' => ServerStatus::Online,
            'players_online' => 10,
            'last_queried_at' => now()->subHour(),
        ]);
    }

    public function test_it_queries_the_server_again_and_answers_with_what_it_found(): void
    {
        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250, map: 'Procedural Map'));

        $response = $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertTrue($response->json('refreshed'));
        // The payload is the panel's new contents, not a job receipt to poll for.
        $this->assertSame(214, $response->json('data.live.players'));
        $this->assertSame('Procedural Map', $response->json('data.map'));
        $this->assertSame(214, $this->server->refresh()->players_online);
    }

    public function test_a_server_checked_moments_ago_is_left_alone(): void
    {
        $this->server->state()->update(['last_queried_at' => now()->subSeconds(5)]);
        $this->fakeDriver(new QueryResult(playersOnline: 999, playersMax: 999));

        $response = $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        // Answered, but without knocking: the cooldown is there to protect an
        // address we do not own from however many people press the button.
        $this->assertFalse($response->json('refreshed'));
        $this->assertSame(10, $response->json('data.live.players'));
        $this->assertSame(10, $this->server->refresh()->players_online);
    }

    /**
     * The bug this covers: the button sits in the "Server information" panel,
     * and everything below the player count in that panel — mode, map size,
     * seed, entities, FPS — comes from a second exchange that the monitor only
     * makes once a day. Pressing refresh moved the players and left the rest of
     * the block exactly as it was, which reads as "it did not save".
     */
    public function test_a_refresh_re_reads_the_facts_the_panel_is_made_of(): void
    {
        // The raw A2S rules, which is what the column holds — every value a
        // string, because that is how they arrive on the wire, and ServerInfo
        // is what turns `gmt` into "mode" for the panel.
        $this->server->forceFill([
            'details' => ['gmt' => 'Vanilla', 'ent_cnt' => '1000'],
            // Synced minutes ago: the daily cadence would skip this.
            'details_synced_at' => now()->subMinutes(5),
        ])->save();

        $this->fakeDriver(
            new QueryResult(playersOnline: 214, playersMax: 250),
            ['gmt' => 'Modded', 'ent_cnt' => '559673'],
        );

        $response = $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertSame('Modded', $response->json('data.info.mode'));
        $this->assertSame(559673, $response->json('data.info.entities'));
        $this->assertTrue($this->server->refresh()->details_synced_at->isAfter(now()->subMinute()));
    }

    /**
     * The scheduled poll keeps the daily cadence: it runs against every server
     * in the catalog, and the second exchange is a second packet at somebody
     * else's machine for facts that change on wipe.
     */
    public function test_the_monitor_still_leaves_those_facts_alone(): void
    {
        $this->server->forceFill([
            'details' => ['gmt' => 'Vanilla'],
            'details_synced_at' => now()->subMinutes(5),
        ])->save();

        $this->fakeDriver(new QueryResult(playersOnline: 3, playersMax: 10), ['gmt' => 'Modded']);

        QueryServer::dispatchSync($this->server);

        $this->assertSame('Vanilla', $this->server->refresh()->details['gmt']);
    }

    /**
     * This used to be the other half of "it did not save": the panel updated,
     * but the page around it was a cached shell, and a reload inside its window
     * brought the old map, facts and graph back — so a refresh had to expire it.
     *
     * The server page is read when it is requested now, so the reload shows
     * what this write just put in the database and there is nothing to expire.
     * The call this made was a synchronous HTTP request, with a timeout, inside
     * a request somebody was waiting on.
     */
    public function test_a_refresh_has_nothing_to_tell_the_frontend(): void
    {
        config([
            'services.frontend.revalidate_url' => 'https://front.test/api/revalidate',
            'services.frontend.revalidate_secret' => 'shhh',
        ]);
        Http::fake();

        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        Http::assertNothingSent();
    }

    /** The same, down the branch where the cooldown declined to re-query. */
    public function test_a_declined_refresh_leaves_the_cache_alone(): void
    {
        config([
            'services.frontend.revalidate_url' => 'https://front.test/api/revalidate',
            'services.frontend.revalidate_secret' => 'shhh',
        ]);
        Http::fake();

        $this->server->state()->update(['last_queried_at' => now()->subSeconds(5)]);
        $this->fakeDriver(new QueryResult(playersOnline: 999, playersMax: 999));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_refresh_records_downtime_like_any_other_check(): void
    {
        $this->fakeDriver(null);

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $server = $this->server->refresh();

        $this->assertSame(ServerStatus::Offline, $server->status);
        $this->assertNotNull($server->last_offline_at);
    }

    public function test_an_inactive_server_cannot_be_refreshed(): void
    {
        $this->server->forceFill(['is_active' => false])->save();

        $this->postJson('/api/servers/refresh-me/refresh')->assertNotFound();
    }

    public function test_the_button_cannot_be_used_to_walk_the_catalog(): void
    {
        $this->fakeDriver(new QueryResult(playersOnline: 1, playersMax: 10));

        // Six a minute per address: enough for a person watching one server,
        // far too few to sweep a listing.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/servers/refresh-me/refresh')->assertOk();
        }

        $this->postJson('/api/servers/refresh-me/refresh')->assertStatus(429);
    }

    /**
     * Null stands for a server that did not answer.
     *
     * @param  array<string, mixed>|null  $details  What the second exchange
     *                                              returns, for the tests that
     *                                              care whether it happened.
     */
    private function fakeDriver(?QueryResult $result, ?array $details = null): void
    {
        $driver = new class($result, $details) implements ProvidesServerDetails, ServerQueryDriver
        {
            public function __construct(private ?QueryResult $result, private ?array $details) {}

            public function query(Server $server): QueryResult
            {
                return $this->result ?? throw QueryFailed::timedOut('1.2.3.4:28015');
            }

            public function details(Server $server): array
            {
                return $this->details ?? [];
            }
        };

        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldReceive('supports')->andReturnTrue();
        $manager->shouldReceive('for')->andReturn($driver);

        $this->app->instance(ServerQueryManager::class, $manager);
    }
}
