<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Discovery\SteamCatalogSync;
use App\Services\Discovery\SteamServerSweep;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            'services.steam.key' => 'test-key',
            // Three rows is a full response here, so the recursion can be proved
            // without building nine thousand of them per test.
            'monitoring.steam_saturated_at' => 3,
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

    /** As many rows as it takes for a response to read as truncated. */
    private function fullResponse(): array
    {
        $rows = [];

        for ($i = 0; $i < (int) config('monitoring.steam_saturated_at'); $i++) {
            $rows[] = $this->row('10.0.0.'.$i, 27015);
        }

        return $rows;
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
