<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\DiscoveredServer;
use App\Services\Discovery\SteamCatalogSync;
use App\Services\Discovery\SteamServerSweep;
use App\Services\Discovery\SteamServerSweepParallel;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The bulk half of monitoring: Steam's own list instead of a packet per server.
 *
 * The two things worth pinning down are the ones that were wrong first time.
 * A response at the cap is not a complete answer and has to be subdivided, and
 * the axes that subdivide it overlap, so the same server arrives more than once
 * and must land as one row.
 */
class SteamSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        $this->seed([CountrySeeder::class, GameSeeder::class]);

        config([
            // Both, and the plural one matters: there is no .env.testing, so a
            // suite run on a developer's machine reads their real STEAM_API_KEY
            // and every one of these tests was quietly exercising it.
            'services.steam.key' => 'test-key',
            'services.steam.keys' => ['test-key'],
            // Three rows is a full response here, so the recursion can be proved
            // without building nine thousand of them per test.
            'monitoring.steam_saturated_at' => 3,
            /*
             * On, though production runs with it off.
             *
             * Most of this file predates the freeze and is about what the sweep
             * reads and writes, which is the same work either way — it just
             * needs the rows to exist to assert against. The freeze itself has
             * its own tests below, and they turn this back off.
             */
            'monitoring.steam_create_new_servers' => true,
        ]);
        Http::preventStrayRequests();
    }

    public function test_it_writes_a_game_the_first_response_covers(): void
    {
        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 12, max: 32)]]);

        $report = $this->sync();

        $this->assertSame(1, $report->found);
        $this->assertSame(1, $report->created);
        $this->assertSame(1, $report->requests);

        $server = Server::firstOrFail();

        $this->assertSame(ServerStatus::Online, $server->status);
        $this->assertSame(12, $server->players_online);
        $this->assertSame(32, $server->players_max);
        $this->assertSame('de_dust2', $server->map);
        $this->assertNotNull($server->steam_seen_at);
    }

    /**
     * The three fields the old discovery path threw away and paid for again
     * with a packet: they are in the same payload, for free.
     */
    public function test_it_keeps_the_steam_id_bots_and_anti_cheat(): void
    {
        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, steamId: '9001', bots: 4, secure: true)]]);

        $this->sync();

        $server = Server::firstOrFail();

        $this->assertSame('9001', $server->steam_id);
        $this->assertSame(4, $server->bots);
        $this->assertTrue($server->vac_enabled);
    }

    /**
     * The failure the first version shipped with. A response at the cap is not
     * a population, it is a truncation, and treating it as complete swept
     * Counter-Strike in one request and called ten thousand servers the whole
     * of it.
     */
    public function test_a_full_response_is_subdivided(): void
    {
        $saturated = $this->fullResponse();

        $this->fakeSteam([
            '' => $saturated,
            '\region\0' => [$this->row('2.2.2.2', 27015)],
        ]);

        $report = $this->sync();

        // The nine regions were asked, on top of the first request.
        $this->assertSame(10, $report->requests);
        $this->assertTrue(Server::where('host', '2.2.2.2')->exists());
    }

    /**
     * The axes are complete but overlapping — a server answers under more than
     * one region — so the same address arriving twice has to be one row.
     */
    public function test_a_server_listed_under_two_axes_lands_once(): void
    {
        $saturated = $this->fullResponse();

        $this->fakeSteam([
            '' => $saturated,
            '\region\0' => [$this->row('3.3.3.3', 27015)],
            '\region\1' => [$this->row('3.3.3.3', 27015)],
        ]);

        $report = $this->sync();

        $this->assertSame(1, Server::where('host', '3.3.3.3')->count());
        // Counted once as well, or the report would overstate the catalog.
        $this->assertSame(count($saturated) + 1, $report->found);
    }

    /** A bucket still full after every axis is a gap, and says so. */
    public function test_it_reports_what_it_could_not_reach(): void
    {
        $saturated = $this->fullResponse();

        Http::fake([
            'api.steampowered.com/*' => Http::response(['response' => ['servers' => $saturated]]),
        ]);

        $this->assertGreaterThan(0, $this->sync()->truncated);
    }

    public function test_it_updates_an_existing_server_without_renaming_it(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'name' => 'The name an owner chose',
            'slug' => 'the-name-an-owner-chose',
            'players_online' => 0,
        ]);

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 40, name: 'whatever steam says')]]);

        $report = $this->sync();

        $server->refresh();

        $this->assertSame(1, $report->updated);
        $this->assertSame(0, $report->created);
        $this->assertSame(40, $server->players_online);
        // The public URL and the owner's title survive; the live name lands in motd.
        $this->assertSame('The name an owner chose', $server->name);
        $this->assertSame('the-name-an-owner-chose', $server->slug);
        $this->assertSame('whatever steam says', $server->motd);
    }

    /**
     * The snapshot is rewritten every sweep and the history is not. Recording
     * every server every five minutes would multiply the largest table in the
     * schema by the ratio between the sweep and the tier.
     */
    public function test_history_follows_the_tier_rather_than_the_sweep(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'next_query_at' => now()->addHour(),
        ]);

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 7)]]);

        $report = $this->sync();

        $this->assertSame(1, $report->updated);
        $this->assertSame(0, $report->sampled);
        $this->assertSame(0, ServerStat::where('server_id', $server->id)->count());
        // Still current on the page, though.
        $this->assertSame(7, $server->refresh()->players_online);
    }

    public function test_history_is_recorded_when_the_tier_says_so(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'next_query_at' => now()->subMinute(),
        ]);

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 150)]]);

        $this->assertSame(1, $this->sync()->sampled);
        $this->assertSame(1, ServerStat::where('server_id', $server->id)->count());
        // And the tier the reading works out to takes over from the lease.
        $this->assertSame(300, (int) now()->diffInSeconds($server->refresh()->next_query_at, absolute: true));
    }

    /** A row somebody deleted is not quietly brought back by the next sweep. */
    public function test_it_leaves_a_deleted_server_deleted(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
        ]);
        $server->delete();

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015)]]);

        $report = $this->sync();

        $this->assertSame(0, $report->created);
        $this->assertSame(0, $report->updated);
        $this->assertSame(0, Server::count());
    }

    /**
     * The API's own `players` field disagrees with the `cp` tag on roughly one
     * server in six — seen live as `players: 4` against `cp3` — and the tag is
     * the one A2S would have returned.
     */
    public function test_it_trusts_the_tag_counts_over_the_api_field(): void
    {
        $found = DiscoveredServer::fromApi([
            'addr' => '1.2.3.4:28017',
            'gameport' => 28015,
            'players' => 74,
            'name' => 'Rust',
            'gametype' => 'mp300,cp72,qp5,born1785121428,gmrust',
        ]);

        $this->assertSame(72, $found->playersOnline);
        $this->assertSame(300, $found->playersMax);
        $this->assertSame(5, $found->playersQueued);
        $this->assertNotNull($found->wipedAt);
    }

    /** A row we cannot address is dropped rather than stored as a broken server. */
    public function test_it_skips_rows_with_an_unusable_address(): void
    {
        $this->assertNull(DiscoveredServer::fromApi(['addr' => 'not-an-address']));
        $this->assertNull(DiscoveredServer::fromApi(['addr' => 'example.com:28015']));
        $this->assertNull(DiscoveredServer::fromApi([]));
    }

    public function test_it_fails_loudly_without_an_api_key(): void
    {
        config(['services.steam.key' => '', 'services.steam.keys' => []]);

        $this->expectExceptionMessage('STEAM_API_KEY is not set');

        $this->sync();
    }

    /**
     * An owner who typed a domain, not an IP.
     *
     * Steam reports an address, so `host` never matches — the row is found by
     * what the monitor resolved instead. Without that the sweep would list the
     * same server a second time under its IP, which is the shape of duplicate
     * this whole matching step exists to prevent.
     */
    public function test_it_matches_a_server_submitted_by_domain(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => 'cs.example.com',
            'port' => 27015,
            'ip_address' => '1.1.1.1',
            'game_port' => null,
        ]);

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 9)]]);

        $report = $this->sync();

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->updated);
        $this->assertSame(9, $server->refresh()->players_online);
        $this->assertSame(1, Server::count());
    }

    /**
     * The key is a query parameter, and a transport failure quotes the URL it
     * was trying — so this message went into the console, into laravel.log and
     * into failed_jobs, in full, every time DNS was slow.
     */
    public function test_a_transport_failure_does_not_quote_the_api_key(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Resolving timed out for https://api.steampowered.com/…?key=test-key&filter=x'
        ));

        try {
            $this->sync();
            $this->fail('The sweep should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            // The reason survives: a resolve timeout and a 403 need different fixes.
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());
        }
    }

    /**
     * A blip four levels into Counter-Strike used to abort the whole game and
     * lose every bucket still unvisited.
     */
    public function test_one_unreachable_bucket_does_not_lose_the_rest(): void
    {
        Http::fake(function ($request) {
            $filter = urldecode((string) ($request->data()['filter'] ?? ''));

            if ($filter === '\appid\730\region\1') {
                throw new ConnectionException('cURL error 28: Resolving timed out');
            }

            return Http::response(['response' => [
                'servers' => $filter === '\appid\730'
                    ? $this->fullResponse()
                    : ($filter === '\appid\730\region\2' ? [$this->row('4.4.4.4', 27015)] : []),
            ]]);
        });

        $report = $this->sync();

        $this->assertSame(1, $report->unreachable);
        // The regions after the one that failed were still asked.
        $this->assertTrue(Server::where('host', '4.4.4.4')->exists());
    }

    /** If the very first question cannot be asked there is nothing to salvage. */
    public function test_a_failure_on_the_first_request_still_fails_the_game(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->expectException(RuntimeException::class);

        $this->sync();
    }

    /** As many rows as it takes for a response to read as truncated. */
    private function fullResponse(): array
    {
        $rows = [];

        for ($i = 0; $i < (int) config('monitoring.steam_saturated_at'); $i++) {
            $rows[] = $this->row('10.0.0.'.$i, 27015);
        }

        return $rows;
    }

    /**
     * The cheap pass. Reading everything every five minutes costs about four
     * hundred requests, which is 115 000 calls a day against a key rated for
     * 100 000; occupied servers are a slice of that and are the ones on the
     * fast tiers anyway.
     */
    public function test_the_populated_pass_asks_only_about_occupied_servers(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $seen[] = urldecode((string) ($request->data()['filter'] ?? ''));

            return Http::response(['response' => ['servers' => []]]);
        });

        app(SteamCatalogSync::class)->run($this->game(), app(SteamServerSweep::class), populatedOnly: true);

        $this->assertSame(['\appid\730\empty\1'], $seen);
    }

    /**
     * Applied on top of `\empty\1`, the emptiness axis splits a bucket into
     * nothing and itself — a request that subdivides nothing.
     */
    public function test_the_populated_pass_does_not_subdivide_by_emptiness(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $filter = urldecode((string) ($request->data()['filter'] ?? ''));
            $seen[] = $filter;

            // Only the top question is full, so exactly one axis is applied.
            return Http::response(['response' => [
                'servers' => $filter === '\appid\730\empty\1' ? $this->fullResponse() : [],
            ]]);
        });

        app(SteamCatalogSync::class)->run($this->game(), app(SteamServerSweep::class), populatedOnly: true);

        $this->assertContains('\appid\730\empty\1\region\0', $seen);
        $this->assertNotContains('\appid\730\empty\1\noplayers\1', $seen);
    }

    /** A second key is a second daily allowance, and nothing else changes. */
    public function test_requests_are_dealt_across_every_key(): void
    {
        config(['services.steam.keys' => ['first', 'second']]);

        $keys = [];

        Http::fake(function ($request) use (&$keys) {
            $filter = urldecode((string) ($request->data()['filter'] ?? ''));
            $keys[] = $request->data()['key'] ?? null;

            return Http::response(['response' => [
                'servers' => $filter === '\appid\730' ? $this->fullResponse() : [],
            ]]);
        });

        $this->sync();

        // Ten requests: the bare question and its nine regions, alternating.
        $this->assertSame('first', $keys[0]);
        $this->assertSame('second', $keys[1]);
        $this->assertSame('first', $keys[2]);
        $this->assertSame(5, count(array_filter($keys, fn ($k) => $k === 'second')));
    }

    /**
     * The freeze, from the sweep's side: Steam offers, the catalog declines.
     */
    public function test_a_frozen_catalog_updates_what_it_holds_and_adds_nothing(): void
    {
        config(['monitoring.steam_create_new_servers' => false]);

        $mine = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'players_online' => 0,
        ]);

        $this->fakeSteam(['' => [
            $this->row('1.1.1.1', 27015, players: 31),
            $this->row('5.5.5.5', 27015, players: 12),
        ]]);

        $report = $this->sync();

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->skipped);
        // Both were listed: `found` is what Steam has, not what we took.
        $this->assertSame(2, $report->found);
        $this->assertSame(31, $mine->refresh()->players_online);
        $this->assertSame(1, Server::count());
    }

    /**
     * The rows nobody asked for are dropped before anything is built from them.
     *
     * Asserted through the tag string, which only `fromApi` reads: a row whose
     * address is not ours lands in `skipped` whatever its `gametype` says, and
     * a malformed one cannot throw because it is never parsed.
     */
    public function test_an_unwanted_row_is_never_parsed(): void
    {
        config(['monitoring.steam_create_new_servers' => false]);

        Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
        ]);

        $this->fakeSteam(['' => [
            $this->row('1.1.1.1', 27015, players: 5),
            ['addr' => '9.9.9.9:27015', 'gameport' => 27015, 'gametype' => str_repeat('cp', 5000)],
        ]]);

        $report = $this->sync();

        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->skipped);
    }

    /**
     * A game that never reports bots must not have the last real answer wiped
     * by a payload that simply does not carry one — which is what a batched
     * update writing every column would do.
     */
    public function test_an_absent_field_does_not_overwrite_a_stored_one(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'steam_id' => '90071992547409',
            'bots' => 6,
            'vac_enabled' => true,
        ]);

        // Two servers, one carrying the soft fields and one not, so they share
        // a batch — the case the old shape split into two statements.
        Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '2.2.2.2',
            'port' => 27015,
        ]);

        $this->fakeSteam(['' => [
            $this->row('1.1.1.1', 27015, players: 3),
            $this->row('2.2.2.2', 27015, players: 4, steamId: '5', bots: 1, secure: true),
        ]]);

        $this->assertSame(2, $this->sync()->updated);

        $server->refresh();

        $this->assertSame('90071992547409', $server->steam_id);
        $this->assertSame(6, $server->bots);
        $this->assertTrue($server->vac_enabled);
        // The measurements it did carry still landed.
        $this->assertSame(3, $server->players_online);
    }

    /**
     * A name carrying the characters that break a hand-built statement.
     *
     * The batch is handed over as one JSON document now, so quotes, backslashes
     * and commas in a server name go through an encoder rather than through
     * string concatenation — and Cyrillic has to survive the round trip intact.
     */
    public function test_a_name_full_of_punctuation_survives_the_batch(): void
    {
        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
        ]);

        $name = 'Вайп "сегодня", 100x \\ {"drop": true} | 50%';

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015, players: 8, name: $name)]]);

        $this->assertSame(1, $this->sync()->updated);

        $server->refresh();

        $this->assertSame($name, $server->motd);
        $this->assertSame(8, $server->players_online);
    }

    /**
     * The parallel sweep writes what the sequential one writes.
     *
     * It exists because the wait on Steam was 320 s of a 439 s sweep, and it is
     * only worth having if the catalog cannot tell which one ran — so the
     * assertions here are deliberately the same ones the sequential path makes.
     */
    public function test_the_parallel_sweep_writes_the_same_catalog(): void
    {
        config(['monitoring.steam_create_new_servers' => false]);

        $mine = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'players_online' => 0,
        ]);

        $this->fakeSteam(['' => [
            $this->row('1.1.1.1', 27015, players: 44),
            $this->row('5.5.5.5', 27015, players: 12),
        ]]);

        $report = app(SteamCatalogSync::class)->run($this->game(), app(SteamServerSweepParallel::class));

        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(0, $report->created);
        $this->assertSame(44, $mine->refresh()->players_online);
        $this->assertGreaterThan(0, $report->steamMs);
    }

    /**
     * A level is asked at once, and only where the level above was full — so a
     * game that fits in one response still costs one request.
     */
    public function test_the_parallel_sweep_expands_only_saturated_buckets(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $filter = urldecode((string) ($request->data()['filter'] ?? ''));
            $seen[] = $filter;

            return Http::response(['response' => [
                'servers' => $filter === '\appid\730' ? $this->fullResponse() : [],
            ]]);
        });

        $report = app(SteamCatalogSync::class)->run($this->game(), app(SteamServerSweepParallel::class));

        // The root plus its nine regions, and nothing below them.
        $this->assertSame(10, $report->requests);
        $this->assertSame(10, count($seen));
        $this->assertContains('\appid\730\region\0', $seen);
        $this->assertNotContains('\appid\730\region\0\empty\1', $seen);
    }

    /**
     * Two Steam rows, one catalog row, one write.
     *
     * A server is keyed under `host:port`, `ip_address:port` and
     * `ip_address:game_port`; when the stored game port differs from the port,
     * the last of those is an address of its own, and a machine running a
     * second server on it gives us two rows that resolve to the same id. On
     * Postgres the history upsert then refuses the batch outright — "ON
     * CONFLICT DO UPDATE command cannot affect row a second time", because
     * `recorded_at` is one timestamp for the whole run. Seen on Rust.
     */
    public function test_two_rows_resolving_to_one_server_are_written_once(): void
    {
        config(['monitoring.steam_create_new_servers' => false]);

        $server = Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
            'ip_address' => '1.1.1.1',
            // Disagrees with `port`, which is what opens the second address.
            'game_port' => 27016,
            'next_query_at' => now()->subHour(),
            'players_online' => 0,
        ]);

        $this->fakeSteam(['' => [
            $this->row('1.1.1.1', 27015, players: 11),
            // A different server on the same host, whose game port is the one
            // the row above already answers to.
            array_merge($this->row('1.1.1.1', 27017, players: 22), ['gameport' => 27016]),
        ]]);

        $report = $this->sync();

        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->sampled);
        $this->assertSame(1, $report->duplicated);
        // One history row, not two colliding on the same second.
        $this->assertSame(1, ServerStat::where('server_id', $server->id)->count());
        // First met wins, which is the rule the sweep dedupes by.
        $this->assertSame(11, $server->refresh()->players_online);
    }

    /** The wall clock, split into the phases that have different fixes. */
    public function test_it_reports_where_the_time_went(): void
    {
        Server::factory()->create([
            'game_id' => $this->game()->id,
            'host' => '1.1.1.1',
            'port' => 27015,
        ]);

        $this->fakeSteam(['' => [$this->row('1.1.1.1', 27015)]]);

        $report = $this->sync();

        $this->assertGreaterThan(0, $report->totalMs);
        $this->assertGreaterThan(0, $report->steamMs);
        $this->assertGreaterThan(0, $report->dbMs);
        // The parts cannot add up to more than the whole.
        $this->assertLessThanOrEqual(
            $report->totalMs + 0.001,
            $report->steamMs + $report->rowsMs + $report->dbMs + $report->existingMs,
        );
    }

    private function game(): Game
    {
        return Game::where('slug', 'counter-strike-2')->firstOrFail();
    }

    private function sync()
    {
        return app(SteamCatalogSync::class)->run($this->game(), app(SteamServerSweep::class));
    }

    /**
     * Answers keyed by what the filter carries beyond the app id, so a test can
     * say "the bare question is full, and this narrower one holds these".
     *
     * @param  array<string, list<array<string, mixed>>>  $byFilter
     */
    private function fakeSteam(array $byFilter): void
    {
        Http::fake(function ($request) use ($byFilter) {
            $filter = urldecode((string) ($request->data()['filter'] ?? ''));
            $suffix = str_replace('\appid\730', '', $filter);

            return Http::response(['response' => ['servers' => $byFilter[$suffix] ?? []]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $ip,
        int $port,
        int $players = 0,
        int $max = 32,
        string $name = 'A server',
        ?string $steamId = null,
        ?int $bots = null,
        ?bool $secure = null,
    ): array {
        return array_filter([
            'addr' => "{$ip}:{$port}",
            'gameport' => $port,
            'name' => $name,
            'players' => $players,
            'max_players' => $max,
            'map' => 'de_dust2',
            'version' => '1.41',
            'gametype' => "mp{$max},cp{$players},qp0",
            'steamid' => $steamId,
            'bots' => $bots,
            'secure' => $secure,
        ], fn ($value) => $value !== null);
    }
}
