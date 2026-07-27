<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Geo\GeoResolver;
use App\Services\Geo\NullGeoResolver;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\PollingSchedule;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cadence assertions compare exact second offsets, and the database
        // stores timestamps without microseconds — freeze on a whole second.
        $this->freezeSecond();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_a_successful_query_updates_the_snapshot_and_records_a_sample(): void
    {
        $server = $this->minecraftServer(['country_id' => null]);

        $this->runJob($server, new QueryResult(
            playersOnline: 128,
            playersMax: 500,
            version: '1.21.4',
            motd: 'Best server',
            latencyMs: 44,
            ipAddress: '8.8.8.8',
        ));

        $server->refresh();

        $this->assertSame(ServerStatus::Online, $server->status);
        $this->assertSame(128, $server->players_online);
        $this->assertSame(500, $server->players_max);
        $this->assertSame('1.21.4', $server->reported_version);
        $this->assertSame('Best server', $server->motd);
        $this->assertSame('8.8.8.8', $server->ip_address);
        $this->assertSame(0, $server->failed_queries_count);
        $this->assertNotNull($server->last_online_at);

        // 128 players puts it in the hottest tier.
        $this->assertSame(
            now()->addSeconds(config('monitoring.tiers.0.interval'))->timestamp,
            $server->next_query_at->timestamp,
        );

        $sample = ServerStat::where('server_id', $server->id)->firstOrFail();
        $this->assertTrue($sample->is_online);
        $this->assertSame(128, $sample->players_online);
        $this->assertSame(44, $sample->latency_ms);
    }

    public function test_it_stores_the_fields_the_server_reports_about_itself(): void
    {
        $server = $this->minecraftServer(['game_port' => null, 'wiped_at' => null]);
        $wipedAt = now()->subDays(2)->startOfSecond();

        $this->runJob($server, new QueryResult(
            playersOnline: 200,
            playersMax: 205,
            map: 'Procedural Map',
            gamePort: 28010,
            wipedAt: $wipedAt,
            playersQueued: 12,
        ));

        $server->refresh();

        $this->assertSame(28010, $server->game_port);
        $this->assertTrue($wipedAt->equalTo($server->wiped_at));
        $this->assertSame(12, $server->players_queued);
        $this->assertSame('Procedural Map', $server->map);
    }

    public function test_it_does_not_wipe_fields_a_protocol_cannot_report(): void
    {
        // Minecraft reports no map, game port, wipe time or queue — a query must
        // leave whatever is already stored alone instead of nulling it.
        $server = $this->minecraftServer([
            'map' => 'skyblock',
            'game_port' => 25566,
            'players_queued' => 7,
        ]);

        $this->runJob($server, new QueryResult(playersOnline: 5, playersMax: 100));

        $server->refresh();

        $this->assertSame('skyblock', $server->map);
        $this->assertSame(25566, $server->game_port);
        $this->assertSame(7, $server->players_queued);
    }

    public function test_the_cadence_tier_follows_the_player_count_just_measured(): void
    {
        $busy = $this->minecraftServer();
        $empty = $this->minecraftServer();

        $this->runJob($busy, new QueryResult(playersOnline: 300, playersMax: 500));
        $this->runJob($empty, new QueryResult(playersOnline: 0, playersMax: 500));

        // Same code path, 30x apart in polling cost.
        $this->assertSame(120, (int) now()->diffInSeconds($busy->refresh()->next_query_at, absolute: true));
        $this->assertSame(3600, (int) now()->diffInSeconds($empty->refresh()->next_query_at, absolute: true));
    }

    public function test_a_promoted_server_keeps_the_hot_cadence_while_empty(): void
    {
        $server = $this->minecraftServer(['promoted_until' => now()->addMonth()]);

        $this->runJob($server, new QueryResult(playersOnline: 0, playersMax: 500));

        $this->assertSame(
            (int) config('monitoring.promoted_interval'),
            (int) now()->diffInSeconds($server->refresh()->next_query_at, absolute: true),
        );
    }

    public function test_a_failed_query_marks_the_server_offline_and_backs_off(): void
    {
        $server = $this->minecraftServer([
            'status' => ServerStatus::Online,
            'players_online' => 50,
            'failed_queries_count' => 0,
        ]);

        $this->runJob($server, QueryFailed::timedOut('1.2.3.4:25565'));

        $server->refresh();

        $this->assertSame(ServerStatus::Offline, $server->status);
        $this->assertSame(0, $server->players_online);
        $this->assertSame(1, $server->failed_queries_count);

        // First failure: interval * 2.
        $this->assertSame(
            now()->addSeconds(config('monitoring.interval') * 2)->timestamp,
            $server->next_query_at->timestamp,
        );

        $sample = ServerStat::where('server_id', $server->id)->firstOrFail();
        $this->assertFalse($sample->is_online);
        $this->assertSame(0, $sample->players_online);
    }

    public function test_backoff_doubles_per_failure_and_stops_at_the_ceiling(): void
    {
        $server = $this->minecraftServer(['failed_queries_count' => 40]);

        $this->runJob($server, QueryFailed::timedOut('1.2.3.4:25565'));

        $this->assertSame(
            now()->addSeconds(config('monitoring.max_interval'))->timestamp,
            $server->refresh()->next_query_at->timestamp,
        );
    }

    public function test_it_assigns_a_country_from_the_resolved_ip(): void
    {
        $server = $this->minecraftServer(['country_id' => null]);

        $geo = new class implements GeoResolver
        {
            public function countryCode(string $ip): ?string
            {
                return 'DE';
            }
        };

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $geo);

        $this->assertSame(
            Country::where('code', 'DE')->value('id'),
            $server->refresh()->country_id,
        );
    }

    public function test_it_leaves_an_existing_country_alone(): void
    {
        $france = Country::where('code', 'FR')->value('id');
        $server = $this->minecraftServer(['country_id' => $france]);

        $geo = new class implements GeoResolver
        {
            public function countryCode(string $ip): ?string
            {
                return 'DE';
            }
        };

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $geo);

        $this->assertSame($france, $server->refresh()->country_id);
    }

    public function test_the_dispatcher_only_picks_servers_that_are_due(): void
    {
        $due = $this->minecraftServer(['next_query_at' => now()->subMinute()]);
        $notDue = $this->minecraftServer(['next_query_at' => now()->addHour()]);
        $inactive = $this->minecraftServer(['next_query_at' => now()->subHour(), 'is_active' => false]);

        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(QueryServer::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(
            QueryServer::class,
            fn (QueryServer $job) => $job->server->is($due),
        );
    }

    public function test_the_dispatcher_skips_games_without_a_driver(): void
    {
        // FiveM speaks plain HTTP; that driver is not written yet.
        $fivem = Game::where('slug', 'fivem')->firstOrFail();
        Server::factory()->create([
            'game_id' => $fivem->id,
            'next_query_at' => now()->subMinute(),
        ]);

        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('servers:query')
            ->expectsOutputToContain('no driver for: fivem')
            ->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_the_dispatcher_queries_rust_servers_now_that_source_is_supported(): void
    {
        $rust = Game::where('slug', 'rust')->firstOrFail();
        $server = Server::factory()->create([
            'game_id' => $rust->id,
            'next_query_at' => now()->subMinute(),
        ]);

        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(
            QueryServer::class,
            fn (QueryServer $job) => $job->server->is($server),
        );
    }

    public function test_the_dispatcher_warns_when_it_falls_behind(): void
    {
        Server::factory()->count(4)->create([
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'next_query_at' => now()->subMinutes(10),
        ]);

        \Illuminate\Support\Facades\Queue::fake();

        // Only two of the four due servers fit in the batch.
        $this->artisan('servers:query', ['--limit' => 2])
            ->expectsOutputToContain('Monitoring is behind: 4 servers due, batch size 2')
            ->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(QueryServer::class, 2);
    }

    public function test_the_dispatcher_holds_back_servers_that_share_a_host(): void
    {
        $minecraft = Game::where('slug', 'minecraft')->value('id');

        // Three servers behind one IP, with a cap of two per host.
        config(['monitoring.max_per_host' => 2]);

        foreach (range(1, 3) as $i) {
            Server::factory()->create([
                'game_id' => $minecraft,
                'ip_address' => '10.0.0.1',
                'next_query_at' => now()->subMinute(),
            ]);
        }

        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('servers:query')
            ->expectsOutputToContain('1 server(s) held back by the per-host cap')
            ->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(QueryServer::class, 2);
    }

    private function minecraftServer(array $attributes = []): Server
    {
        $minecraft = Game::where('slug', 'minecraft')->firstOrFail();

        return Server::factory()->create($attributes + ['game_id' => $minecraft->id]);
    }

    /**
     * Run the job against a driver that returns a canned result or throws.
     */
    private function runJob(Server $server, QueryResult|QueryFailed $outcome, ?GeoResolver $geo = null): void
    {
        $driver = new class($outcome) implements ServerQueryDriver
        {
            public function __construct(private QueryResult|QueryFailed $outcome) {}

            public function query(Server $server): QueryResult
            {
                if ($this->outcome instanceof QueryFailed) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };

        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldReceive('for')->andReturn($driver);

        (new QueryServer($server))->handle($manager, $geo ?? new NullGeoResolver, new PollingSchedule);
    }
}
