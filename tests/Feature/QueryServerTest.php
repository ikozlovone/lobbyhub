<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Services\Geo\GeoLocation;
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
use Illuminate\Support\Facades\Queue;
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

    public function test_it_stores_the_bot_count_and_anti_cheat_flag(): void
    {
        $server = $this->minecraftServer();

        $this->runJob($server, new QueryResult(playersOnline: 4, playersMax: 100, bots: 3, vacEnabled: true));

        $server->refresh();

        $this->assertSame(3, $server->bots);
        $this->assertTrue($server->vac_enabled);
    }

    public function test_no_bots_is_stored_as_none_rather_than_left_unknown(): void
    {
        // Zero is an answer a Source server gives; null means we never asked a
        // protocol that has the concept, and the two must not collapse.
        $server = $this->minecraftServer();

        $this->runJob($server, new QueryResult(playersOnline: 4, playersMax: 100, bots: 0, vacEnabled: false));

        $server->refresh();

        $this->assertSame(0, $server->bots);
        $this->assertFalse($server->vac_enabled);
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

        // Same code path, 12x apart in polling cost.
        $this->assertSame(300, (int) now()->diffInSeconds($busy->refresh()->next_query_at, absolute: true));
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
        // The pair is what makes reliability readable: last seen up, last seen
        // down. Only the second one moves here.
        $this->assertSame(now()->timestamp, $server->last_offline_at->timestamp);

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

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $this->geo('DE'));

        $this->assertSame(
            Country::where('code', 'DE')->value('id'),
            $server->refresh()->country_id,
        );
    }

    public function test_it_stores_the_city_when_the_city_database_is_in_use(): void
    {
        $server = $this->minecraftServer(['country_id' => null, 'city' => null]);

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $this->geo('DE', 'Frankfurt'));

        $this->assertSame('Frankfurt', $server->refresh()->city);
    }

    /**
     * A server placed by the Country database has no city. Dropping the City
     * database in later has to fill it without any backfill step.
     */
    public function test_a_server_with_a_country_but_no_city_is_looked_up_again(): void
    {
        $server = $this->minecraftServer([
            'country_id' => Country::where('code', 'DE')->value('id'),
            'city' => null,
        ]);

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $this->geo('DE', 'Berlin'));

        $this->assertSame('Berlin', $server->refresh()->city);
    }

    private function geo(?string $country, ?string $city = null): GeoResolver
    {
        return new class($country, $city) implements GeoResolver
        {
            public function __construct(private ?string $country, private ?string $city) {}

            public function locate(string $ip): ?GeoLocation
            {
                return new GeoLocation($this->country, $this->city);
            }
        };
    }

    public function test_it_leaves_an_existing_country_alone(): void
    {
        $france = Country::where('code', 'FR')->value('id');
        $server = $this->minecraftServer(['country_id' => $france, 'city' => 'Paris']);

        $this->runJob($server, new QueryResult(ipAddress: '5.6.7.8'), $this->geo('DE', 'Berlin'));

        $this->assertSame($france, $server->refresh()->country_id);
    }

    public function test_the_dispatcher_only_picks_servers_that_are_due(): void
    {
        $due = $this->minecraftServer(['next_query_at' => now()->subMinute()]);
        $notDue = $this->minecraftServer(['next_query_at' => now()->addHour()]);
        $inactive = $this->minecraftServer(['next_query_at' => now()->subHour(), 'is_active' => false]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
        Queue::assertPushed(
            QueryServer::class,
            fn (QueryServer $job) => $job->server->is($due),
        );
    }

    /**
     * Every protocol currently has a driver, so this path can only be reached by
     * a protocol added ahead of its driver. It still has to hold: those servers
     * must be skipped with a warning rather than failed on every cycle.
     */
    public function test_the_dispatcher_skips_games_without_a_driver(): void
    {
        $fivem = Game::where('slug', 'fivem')->firstOrFail();
        Server::factory()->create([
            'game_id' => $fivem->id,
            'next_query_at' => now()->subMinute(),
        ]);

        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldReceive('supports')->andReturn(false);
        $this->instance(ServerQueryManager::class, $manager);

        Queue::fake();

        $this->artisan('servers:query')
            ->expectsOutputToContain('no driver for: fivem')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_dispatcher_queries_fivem_servers_now_that_a_driver_exists(): void
    {
        $fivem = Game::where('slug', 'fivem')->firstOrFail();
        $server = Server::factory()->create([
            'game_id' => $fivem->id,
            'next_query_at' => now()->subMinute(),
        ]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(
            QueryServer::class,
            fn (QueryServer $job) => $job->server->is($server),
        );
    }

    public function test_the_dispatcher_queries_rust_servers_now_that_source_is_supported(): void
    {
        $rust = Game::where('slug', 'rust')->firstOrFail();
        $server = Server::factory()->create([
            'game_id' => $rust->id,
            'next_query_at' => now()->subMinute(),
        ]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(
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

        Queue::fake();

        // Only two of the four due servers fit in the batch.
        $this->artisan('servers:query', ['--limit' => 2])
            ->expectsOutputToContain('Monitoring is behind: 4 servers due, batch size 2')
            ->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 2);
    }

    /** One game's servers, when the question is about that game. */
    public function test_it_can_be_narrowed_to_one_game(): void
    {
        $mine = $this->minecraftServer(['next_query_at' => now()->subMinute()]);

        $rust = Game::where('slug', 'rust')->firstOrFail();
        Server::factory()->create(['game_id' => $rust->id, 'next_query_at' => now()->subMinute()]);

        Queue::fake();

        $this->artisan('servers:query', ['--game' => 'minecraft'])->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
        Queue::assertPushed(fn (QueryServer $job) => $job->server->is($mine));
    }

    /** A slug nobody has is said out loud rather than polling everything. */
    public function test_an_unknown_game_is_refused(): void
    {
        $this->minecraftServer(['next_query_at' => now()->subMinute()]);

        Queue::fake();

        $this->artisan('servers:query', ['--game' => 'not-a-game'])->assertFailed();

        Queue::assertNothingPushed();
    }

    /**
     * A game the sweep has just covered answers "nothing due" to the ordinary
     * question — fresh `steam_seen_at`, `next_query_at` pushed out by its tier —
     * which is right on the timetable and useless when somebody is asking
     * whether the game is actually alive.
     */
    public function test_ignore_schedule_takes_servers_the_sweep_has_covered(): void
    {
        $this->minecraftServer([
            'next_query_at' => now()->addHour(),
            'steam_seen_at' => now(),
            'players_online' => 40,
        ]);

        Queue::fake();

        $this->artisan('servers:query')->expectsOutputToContain('No servers due.')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('servers:query', ['--ignore-schedule' => true])->assertSuccessful();
        Queue::assertPushed(QueryServer::class, 1);
    }

    /**
     * The bug this guards against filled a production queue with 160 000 jobs
     * for 23 000 servers: seven copies of each, because a job waiting its turn
     * left its server due and oldest-due, so every run queued it again.
     */
    public function test_a_second_run_does_not_queue_what_the_first_one_already_did(): void
    {
        $this->minecraftServer(['next_query_at' => now()->subMinute()]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();
        // Nothing has run the job yet — the worker is busy, or there isn't one.
        $this->artisan('servers:query')->expectsOutputToContain('No servers due.')->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
    }

    /**
     * The other half of that guard, for the case the lease cannot cover.
     *
     * The lease only holds while the queue drains inside five minutes. Once it
     * does not, the server falls due again with its first job still waiting —
     * and this is what stops the second one being made. Measured on a stalled
     * queue before it existed: 20,882 jobs for 314 servers.
     */
    public function test_a_server_with_a_query_already_waiting_is_not_queued_again(): void
    {
        $server = $this->minecraftServer(['next_query_at' => now()->subHour()]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        // Five minutes on, the lease has run out and nothing has drained the
        // queue — exactly the state that used to multiply.
        $this->travel((int) config('monitoring.interval') + 1)->seconds();
        $server->refresh();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
    }

    /**
     * The lock is what the queue is protected by; this is what a worker is
     * protected by. A copy that exists anyway — queued before the lock shipped,
     * or after its expiry — must not cost a socket and a stat row.
     */
    public function test_a_job_overtaken_by_another_query_does_nothing(): void
    {
        $server = $this->minecraftServer([
            'players_online' => 3,
            'last_queried_at' => now()->subHour(),
        ]);

        $stale = new QueryServer($server);

        // Somebody else reaches the server while this job waits its turn.
        $this->travel(30)->seconds();
        $server->state()->update(['players_online' => 99, 'last_queried_at' => now()]);

        $this->travel(30)->seconds();
        $this->runQueuedJob($stale, new QueryResult(playersOnline: 3, playersMax: 20));

        // Neither the reading nor the history was touched by the copy.
        $this->assertSame(99, $server->refresh()->players_online);
        $this->assertSame(0, ServerStat::where('server_id', $server->id)->count());
    }

    /** The same job, when nothing overtook it, still does its work. */
    public function test_a_job_that_was_not_overtaken_runs_normally(): void
    {
        $server = $this->minecraftServer(['last_queried_at' => now()->subHour()]);

        $job = new QueryServer($server);

        $this->travel(60)->seconds();
        $this->runQueuedJob($job, new QueryResult(playersOnline: 7, playersMax: 20));

        $this->assertSame(7, $server->refresh()->players_online);
        $this->assertSame(1, ServerStat::where('server_id', $server->id)->count());
    }

    /**
     * A job queued before `queuedAt` existed still works.
     *
     * Those were serialized without the field, and a typed property with no
     * default is an error to read rather than a null — so the guard threw on
     * every one of them, and at `tries = 1` that sent a whole backlog to
     * failed_jobs without a single query being made. Null stands for "queued by
     * a version that did not record this", and the only safe reading of that is
     * to do the work.
     */
    public function test_a_job_queued_before_this_field_existed_still_runs(): void
    {
        $server = $this->minecraftServer(['last_queried_at' => now()->subHour()]);

        $job = new QueryServer($server);
        $job->queuedAt = null;

        // Something reached the server since — which would normally skip it.
        $server->state()->update(['last_queried_at' => now()]);

        $this->runQueuedJob($job, new QueryResult(playersOnline: 12, playersMax: 20));

        $this->assertSame(12, $server->refresh()->players_online);
    }

    /**
     * The refresh button and the submission form run inline because somebody is
     * looking at the panel. Neither may be silenced by a scheduled poll that
     * happens to have touched the server a moment ago.
     */
    public function test_a_query_somebody_asked_for_is_never_skipped(): void
    {
        $server = $this->minecraftServer(['last_queried_at' => now()->addHour()]);

        $this->runQueuedJob(
            new QueryServer($server, forceDetails: true),
            new QueryResult(playersOnline: 5, playersMax: 20),
        );

        $this->assertSame(5, $server->refresh()->players_online);
    }

    public function test_a_queued_server_comes_back_after_the_base_interval(): void
    {
        $server = $this->minecraftServer(['next_query_at' => now()->subHour()]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        // Short enough that a job lost with its worker is retried soon, long
        // enough that the wait outlives any batch it could still be sitting in.
        $this->assertSame(
            now()->addSeconds((int) config('monitoring.interval'))->timestamp,
            $server->refresh()->next_query_at->timestamp,
        );
    }

    /**
     * The lease is a placeholder, not the cadence. Whatever the tier works out
     * when the query lands has to win — otherwise every server would settle at
     * five minutes regardless of how busy it is.
     */
    public function test_the_query_overwrites_the_lease_with_the_real_tier(): void
    {
        $server = $this->minecraftServer(['next_query_at' => now()->subHour()]);

        $this->artisan('servers:query', ['--sync' => true, '--limit' => 1])->assertSuccessful();

        $this->runJob($server, new QueryResult(playersOnline: 0, playersMax: 20));

        $this->assertSame(3600, (int) now()->diffInSeconds($server->refresh()->next_query_at, absolute: true));
    }

    /**
     * `--server=` is the "look at this one now" escape hatch: it ignores the
     * schedule going in, and must not rewrite it on the way out either.
     */
    public function test_a_single_named_server_is_not_leased(): void
    {
        $server = $this->minecraftServer(['next_query_at' => now()->addHour()]);
        $scheduled = $server->next_query_at->timestamp;

        Queue::fake();

        $this->artisan('servers:query', ['--server' => $server->slug])->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
        $this->assertSame($scheduled, $server->refresh()->next_query_at->timestamp);
    }

    /**
     * The point of the Steam sweep: a server it has just read needs no packet.
     * Everything a query would have returned is already in the row, and the
     * five seconds a silent one costs is the whole reason this exists.
     */
    public function test_the_dispatcher_leaves_servers_the_steam_sweep_just_read(): void
    {
        $this->minecraftServer(['next_query_at' => now()->subHour(), 'steam_seen_at' => now()]);

        Queue::fake();

        $this->artisan('servers:query')->expectsOutputToContain('No servers due.')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * And the case the sweep cannot answer. Absence from Steam's list is either
     * a server that is off or one running without a login token, and telling
     * those apart is what a packet is still for.
     */
    public function test_a_server_the_sweep_has_stopped_seeing_is_queried_again(): void
    {
        $this->minecraftServer([
            'next_query_at' => now()->subHour(),
            'steam_seen_at' => now()->subSeconds((int) config('monitoring.steam_trust_quiet') + 60),
            'players_online' => 0,
        ]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
    }

    /**
     * An empty server is only in the half-hourly full pass, so it has to be
     * trusted for longer than one that the five-minute occupied pass covers.
     *
     * These were a single window of 900 seconds against a pass running every
     * 1800, which meant every empty server in the catalog was uncovered for
     * half of each cycle. At a hundred thousand of them that was the whole
     * catalog landing on a poller doing three thousand an hour.
     */
    public function test_an_empty_server_is_trusted_for_longer_than_a_busy_one(): void
    {
        $seenAt = now()->subSeconds((int) config('monitoring.steam_trust_populated') + 60);

        $this->minecraftServer(['next_query_at' => now()->subHour(), 'steam_seen_at' => $seenAt, 'players_online' => 0]);
        $busy = $this->minecraftServer(['next_query_at' => now()->subHour(), 'steam_seen_at' => $seenAt, 'players_online' => 40]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        // Only the busy one: the empty one is still inside its own window.
        Queue::assertPushed(QueryServer::class, 1);
        Queue::assertPushed(QueryServer::class, fn (QueryServer $job) => $job->server->is($busy));
    }

    /**
     * A backlog past the ceiling is the workers saying they cannot keep up, and
     * another batch on top reaches nothing new. Unbounded it grew to a hundred
     * thousand, and everything queued behind it — the Steam sweeps that would
     * have taken most of the catalog off this queue — was a day and a half from
     * running.
     */
    public function test_the_dispatcher_stops_adding_to_a_queue_that_is_already_full(): void
    {
        $this->minecraftServer(['next_query_at' => now()->subHour()]);

        config(['monitoring.max_queue_depth' => 1]);

        Queue::fake();
        // One job already waiting is the ceiling here.
        QueryServer::dispatch($this->minecraftServer());

        $this->artisan('servers:query')
            ->expectsOutputToContain('at or past the 1 ceiling')
            ->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
    }

    /** Minecraft and FiveM are not on Steam at all, and are polled as they always were. */
    public function test_a_server_never_seen_on_steam_is_queried_as_before(): void
    {
        $this->minecraftServer(['next_query_at' => now()->subHour(), 'steam_seen_at' => null]);

        Queue::fake();

        $this->artisan('servers:query')->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 1);
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

        Queue::fake();

        $this->artisan('servers:query')
            ->expectsOutputToContain('1 server(s) held back by the per-host cap')
            ->assertSuccessful();

        Queue::assertPushed(QueryServer::class, 2);
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
        $this->runQueuedJob(new QueryServer($server), $outcome, $geo);
    }

    /**
     * The same, for a job built earlier than it is run — which is the whole
     * point when what is being tested is how long it sat in the queue.
     */
    private function runQueuedJob(QueryServer $job, QueryResult|QueryFailed $outcome, ?GeoResolver $geo = null): void
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

        $job->handle($manager, $geo ?? new NullGeoResolver, new PollingSchedule);
    }
}
