<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\GameMonitoringClient;
use App\Services\Discovery\GameMonitoringSync;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reconciling the catalog against gamemonitoring.net.
 *
 * The pass is half of a deletion decision — what it does not mark is what a
 * cleanup would later remove — so the tests here are mostly about restraint:
 * what it refuses to write is more important than what it writes.
 */
class GameMonitoringSyncTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        // Rust, because GameSeeder gives it a steam_appid and the API is keyed
        // by one.
        $this->game = Game::where('slug', 'rust')->firstOrFail();
    }

    public function test_it_marks_a_server_the_catalog_already_has(): void
    {
        $ours = $this->server('45.152.161.10', 28015);
        $theirs = $this->server('1.2.3.4', 28015);

        $this->listing([$this->item('45.152.161.10', 28015)]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(1, $report->matched);
        $this->assertSame(1, $report->marked);
        $this->assertSame(0, $report->created);
        $this->assertNotNull($ours->refresh()->gamemonitoring_seen_at);
        // The one they do not list is the whole point: it stays unmarked, and
        // that is what a cleanup would later act on.
        $this->assertNull($theirs->refresh()->gamemonitoring_seen_at);
    }

    /**
     * The mark says "first matched", not "last seen" — `servers` is the cold
     * half of the schema and rewriting a date across it every pass is exactly
     * the write pattern the split exists to avoid.
     */
    public function test_a_server_already_marked_keeps_the_date_it_had(): void
    {
        $marked = now()->subMonth();
        $server = $this->server('45.152.161.10', 28015, ['gamemonitoring_seen_at' => $marked]);

        $this->listing([$this->item('45.152.161.10', 28015)]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(1, $report->matched);
        $this->assertSame(0, $report->marked);
        $this->assertSame(
            $marked->timestamp,
            $server->refresh()->gamemonitoring_seen_at->timestamp,
        );
    }

    /**
     * An address nobody here has becomes a row — and a row that carries an
     * address and nothing else. Their name, player count and map are somebody
     * else's measurement of a machine we are about to query ourselves.
     */
    public function test_it_adds_an_address_the_catalog_does_not_have(): void
    {
        $this->listing([
            $this->item('5.62.114.92', 7779, query: 7780, name: 'NA-PVP-Astraeos2575'),
        ]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(1, $report->created);

        $server = Server::where('host', '5.62.114.92')->firstOrFail();

        $this->assertSame(7779, $server->port);
        $this->assertSame(7780, $server->query_port);
        $this->assertSame('5.62.114.92', $server->ip_address);
        $this->assertNotNull($server->gamemonitoring_seen_at);
        // Named after itself, so QueryServer::adoptReportedName replaces both
        // the name and the slug on the first successful query.
        $this->assertSame('5.62.114.92:7779', $server->name);
        $this->assertSame('5-62-114-92-7779', $server->slug);

        // Hearsay until our own packet says otherwise: hidden from every
        // listing, and due for a query in this cycle rather than the next tier
        // interval.
        $state = $server->state;
        $this->assertSame(ServerStatus::Unknown, $state->status);
        $this->assertSame(0, $state->players_online);
        $this->assertNotNull($state->next_query_at);
    }

    /** A server somebody here deleted must not come back through their list. */
    public function test_a_deleted_server_is_not_resurrected(): void
    {
        $server = $this->server('45.152.161.10', 28015);
        $server->delete();

        $this->listing([$this->item('45.152.161.10', 28015)]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(0, $report->created);
        $this->assertSame(0, $report->marked);
        $this->assertSame(1, $report->skipped);
        $this->assertNull($server->fresh()->gamemonitoring_seen_at);
    }

    /**
     * Their list carries rows with the address withheld and rows whose port is
     * zero. Neither is something to write down as a server.
     */
    public function test_rows_without_a_usable_address_are_skipped(): void
    {
        $this->listing([
            ['ip' => '', 'port' => 28015, 'query' => 28016],
            ['ip' => '1.2.3.4', 'port' => 0, 'query' => 0],
            ['ip' => 'not-an-ip', 'port' => 28015, 'query' => 28015],
        ]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(3, $report->found);
        $this->assertSame(3, $report->skipped);
        $this->assertSame(0, $report->created);
        $this->assertSame(0, Server::where('game_id', $this->game->id)->count());
    }

    /**
     * The trap a densely hosted box sets.
     *
     * Rust's convention is query port = game port + 1, so one machine running
     * servers on 28015 and 28016 has a query port for the first that is the
     * connect port of the second. Keying our rows by their query port made the
     * second one look like a server we already had — and that match is wrong
     * twice over without saying so: our row for 28015 gets the mark, so a
     * cleanup spares it, and the real server on 28016 is never added.
     */
    public function test_a_neighbours_connect_port_is_not_our_query_port(): void
    {
        $ours = $this->server('45.152.161.10', 28015, ['query_port' => 28016]);

        $this->listing([
            $this->item('45.152.161.10', 28015, query: 28016),
            $this->item('45.152.161.10', 28016, query: 28017),
        ]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(1, $report->matched);
        $this->assertSame(1, $report->created);

        $neighbour = Server::where('host', '45.152.161.10')->where('port', 28016)->first();
        $this->assertNotNull($neighbour, 'The server on the next port along is its own row.');
        $this->assertSame($ours->id, Server::where('port', 28015)->firstOrFail()->id);
    }

    /** One machine listed twice is one row, not two. */
    public function test_the_same_address_twice_in_one_list_is_added_once(): void
    {
        $this->listing([
            $this->item('5.62.114.92', 7779),
            $this->item('5.62.114.92', 7779),
        ]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(1, $report->created);
        $this->assertSame(1, Server::where('host', '5.62.114.92')->count());
    }

    /**
     * The end of their list is a short page — the envelope carries no total,
     * so anything else would be guessing.
     */
    public function test_it_reads_every_page_until_a_short_one(): void
    {
        config(['services.gamemonitoring.page_size' => 2]);
        $this->app->forgetInstance(GameMonitoringClient::class);

        Http::fake([
            'api.gamemonitoring.net/*' => Http::sequence()
                ->push($this->payload([$this->item('1.1.1.1', 28015), $this->item('1.1.1.2', 28015)]))
                ->push($this->payload([$this->item('1.1.1.3', 28015), $this->item('1.1.1.4', 28015)]))
                ->push($this->payload([$this->item('1.1.1.5', 28015)])),
        ]);

        $report = $this->sync()->run($this->game);

        $this->assertSame(3, $report->pages);
        $this->assertSame(5, $report->found);
        $this->assertSame(5, $report->created);
    }

    /** `--dry-run`: the same numbers, none of the writes. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $ours = $this->server('45.152.161.10', 28015);

        $this->listing([
            $this->item('45.152.161.10', 28015),
            $this->item('5.62.114.92', 7779),
        ]);

        $report = $this->sync()->run($this->game, write: false);

        $this->assertSame(1, $report->marked);
        $this->assertSame(1, $report->created);
        $this->assertNull($ours->refresh()->gamemonitoring_seen_at);
        $this->assertSame(0, Server::where('host', '5.62.114.92')->count());
    }

    /** The appid on the request is the one in our own games table. */
    public function test_it_asks_for_the_games_own_steam_appid(): void
    {
        $this->listing([]);

        $this->sync()->run($this->game);

        Http::assertSent(function ($request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://api.gamemonitoring.net/servers')
                && $query['game'] === (string) $this->game->steam_appid
                && $query['offset'] === '0';
        });
    }

    private function sync(): GameMonitoringSync
    {
        return $this->app->make(GameMonitoringSync::class);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function listing(array $items): void
    {
        Http::fake(['api.gamemonitoring.net/*' => Http::response($this->payload($items))]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return ['response' => ['items' => $items]];
    }

    /** One of their rows, trimmed to the fields this reads plus the noise. */
    private function item(string $ip, int $port, ?int $query = null, string $name = 'Their name'): array
    {
        return [
            'id' => 13895148,
            'name' => $name,
            'status' => true,
            'numplayers' => 69,
            'maxplayers' => 70,
            'country' => 'RU',
            'map' => 'Procedural Map',
            'connect' => "{$ip}:{$port}",
            'ip' => $ip,
            'port' => $port,
            'query' => $query ?? $port,
            'domain' => null,
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
            ...$attributes,
        ]);
    }
}
