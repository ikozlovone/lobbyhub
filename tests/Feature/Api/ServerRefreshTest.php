<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\ServerHistory;
use App\Services\Http\SharedCache;
use App\Services\Monitoring\Contracts\ProvidesServerDetails;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use App\Services\Stats\ClickHouseClient;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * The other half of a refresh: the graph under the panel.
     *
     * The Go sweeper writes one row per online server per sweep into
     * `server_players_raw`, and between sweeps the graph is ten minutes stale —
     * which is exactly the window somebody presses this button in. So the
     * interactive path writes the same row, by the same rules: only a server
     * that answered, timestamp floored to the ten-minute bucket in UTC, so the
     * point lands on top of the sweep's rather than beside it.
     */
    public function test_a_refresh_writes_the_reading_to_clickhouse(): void
    {
        $this->withClickHouse();
        // 14:07 is mid-bucket: the row has to be stamped 14:00, the same mark
        // `time.Now().UTC().Truncate(10 * time.Minute)` produces in the sweeper.
        $this->travelTo(Carbon::parse('2026-09-01 14:07:31', 'UTC'));

        // The fixture was stamped by the real clock in setUp, and the clock has
        // just moved to a fixed point in the past. Left alone, `last_queried_at`
        // sits in the future for anybody running the suite after 15:07 UTC, the
        // cooldown declines to re-query, and the test fails by the hour of the
        // day rather than by anything in the code.
        $this->server->state()->update(['last_queried_at' => now()->subHour()]);

        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        Http::assertSent(function ($request) {
            $expected = 'INSERT INTO server_players_raw (ts, game_id, server_id, players_online) '
                ."FORMAT TabSeparated\n"
                ."2026-09-01 14:00:00\t{$this->server->game_id}\t{$this->server->id}\t214\n";

            return $request->body() === $expected;
        });
    }

    /**
     * A server that did not answer has no player count to record, and a zero
     * would be a measurement we did not take. The sweeper only queues rows for
     * results it got an info block back for; this is the same rule.
     */
    public function test_a_refresh_that_finds_the_server_down_records_no_sample(): void
    {
        $this->withClickHouse();
        $this->fakeDriver(null);

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        Http::assertNothingSent();
    }

    /** Inside the cooldown nothing was measured, so there is nothing to write. */
    public function test_a_declined_refresh_records_no_sample(): void
    {
        $this->withClickHouse();
        $this->server->state()->update(['last_queried_at' => now()->subSeconds(5)]);
        $this->fakeDriver(new QueryResult(playersOnline: 999, playersMax: 999));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        Http::assertNothingSent();
    }

    /**
     * A ClickHouse that is down must not cost somebody their refresh: the state
     * write has already committed by the time the sample is offered, and the
     * panel is what the visitor is waiting on.
     */
    public function test_a_clickhouse_that_is_down_does_not_fail_the_refresh(): void
    {
        $this->withClickHouse();
        Http::fake(['*' => Http::response('Code: 999. DB::Exception', 500)]);

        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250));

        $response = $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertSame(214, $response->json('data.live.players'));
        $this->assertSame(214, $this->server->refresh()->players_online);
    }

    /**
     * The half of the button that only shows up on reload.
     *
     * A server's own read is cacheable for a minute (`cache.public:60`), which
     * is right until the moment somebody proves it wrong by pressing Refresh.
     * The panel is replaced from this response, so without the drop below the
     * page would go back in time on the very next reload — the failure that
     * reads as "the refresh did not save".
     */
    public function test_a_refresh_drops_the_stored_copy_of_the_page_read(): void
    {
        $stored = $this->cachedCopyOf('/api/servers/refresh-me');

        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertFileDoesNotExist($stored);
    }

    /**
     * Nothing was measured inside the cooldown, so the stored copy is still
     * the right answer — and dropping it would buy a PHP boot to store the
     * same bytes again.
     */
    public function test_a_declined_refresh_leaves_the_stored_copy_alone(): void
    {
        $stored = $this->cachedCopyOf('/api/servers/refresh-me');

        $this->server->state()->update(['last_queried_at' => now()->subSeconds(5)]);
        $this->fakeDriver(new QueryResult(playersOnline: 999, playersMax: 999));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertFileExists($stored);
    }

    /**
     * The chart under the panel is cached for ten minutes, being the heaviest
     * read in the API — so without this the graph keeps its pre-refresh shape
     * long after the number above it has moved.
     *
     * Only the ranges the new sample lands in. 30d and 1y are drawn from the
     * daily rollup a cron writes; re-running them would be the expensive half
     * of this endpoint spent on an identical answer.
     */
    public function test_a_refresh_drops_the_chart_ranges_the_new_sample_lands_in(): void
    {
        Cache::put(ServerHistory::cacheKey($this->server, '24h'), ['stale'], 600);
        Cache::put(ServerHistory::cacheKey($this->server, '7d'), ['stale'], 600);
        Cache::put(ServerHistory::cacheKey($this->server, '30d'), ['stale'], 600);

        $this->fakeDriver(new QueryResult(playersOnline: 214, playersMax: 250));

        $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        $this->assertFalse(Cache::has(ServerHistory::cacheKey($this->server, '24h')));
        $this->assertFalse(Cache::has(ServerHistory::cacheKey($this->server, '7d')));
        $this->assertTrue(Cache::has(ServerHistory::cacheKey($this->server, '30d')));
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
     * Stand up a cache directory with an entry for one URI in it, laid out the
     * way nginx lays one out, and return the file so a test can say whether it
     * survived.
     */
    private function cachedCopyOf(string $uri): string
    {
        $root = sys_get_temp_dir().'/lobbyhub-cache-'.bin2hex(random_bytes(6));

        config(['services.nginx.cache_path' => $root]);
        $this->app->forgetInstance(SharedCache::class);

        $hash = md5('GET'.$uri);
        $dir = $root.'/'.substr($hash, 31, 1).'/'.substr($hash, 29, 2);

        mkdir($dir, 0755, recursive: true);
        file_put_contents($file = $dir.'/'.$hash, 'stored answer');

        return $file;
    }

    /**
     * Point the client at a ClickHouse that exists, and fake the wire.
     *
     * The client is a singleton built from config, so it has to be forgotten
     * before the host takes effect — a test that resolved it first (through a
     * page render, say) would otherwise hold the unconfigured one.
     */
    private function withClickHouse(): void
    {
        config(['services.clickhouse.host' => 'clickhouse.test']);
        $this->app->forgetInstance(ClickHouseClient::class);
        Http::fake();
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
