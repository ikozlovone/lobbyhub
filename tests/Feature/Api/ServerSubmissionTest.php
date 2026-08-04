<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\User;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_a_reachable_server_is_listed_straight_away(): void
    {
        $this->driverReturns(new QueryResult(
            playersOnline: 42,
            playersMax: 200,
            version: '1.21.4',
            motd: 'Nordic Survival',
            ipAddress: '8.8.8.8',
        ));

        $response = $this->postJson('/api/games/minecraft/servers', [
            'address' => '8.8.8.8:25565',
        ])->assertCreated();

        $server = Server::firstOrFail();

        $this->assertSame('Nordic Survival', $server->name);
        $this->assertSame('8.8.8.8', $server->host);
        $this->assertSame(25565, $server->port);
        $this->assertSame(200, $server->players_max);
        $this->assertSame('nordic-survival-8-8-8-8-25565', $server->slug);
        $this->assertSame($server->slug, $response->json('data.slug'));

        // The form reached the server to decide whether to save it at all, so
        // there is nothing left to confirm — the measurement it took is the
        // published one, and the owner sees their listing immediately.
        $this->assertSame(ServerStatus::Online, $server->status);
        $this->assertSame(42, $server->players_online);
        $this->assertNotNull($server->last_online_at);

        $listing = $this->getJson('/api/games/minecraft/servers')->assertOk();

        $listing->assertJsonCount(1, 'data');
        $this->assertSame($server->slug, $listing->json('data.0.slug'));
    }

    /**
     * Who added a server is worth knowing, and it is the only moment we can
     * learn it: discovery imports have no submitter, and nothing later can tell
     * one apart from the other.
     */
    public function test_it_records_who_submitted_the_server_when_they_are_signed_in(): void
    {
        $this->driverReturns(new QueryResult(playersOnline: 1, playersMax: 10, ipAddress: '8.8.8.8'));

        $user = User::factory()->create();

        $this->withToken($user->createToken('web')->plainTextToken)
            ->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])
            ->assertCreated();

        $this->assertSame($user->id, Server::firstOrFail()->submitted_by_user_id);
    }

    public function test_a_submission_without_a_session_records_nobody(): void
    {
        $this->driverReturns(new QueryResult(playersOnline: 1, playersMax: 10, ipAddress: '8.8.8.8'));

        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])->assertCreated();

        $this->assertNull(Server::firstOrFail()->submitted_by_user_id);
    }

    public function test_the_catalog_counts_the_new_server_before_the_form_answers(): void
    {
        $this->driverReturns(new QueryResult(playersOnline: 42, playersMax: 200, motd: 'Nordic Survival'));

        $before = $this->getJson('/api/games')->json('data');
        $minecraft = collect($before)->firstWhere('slug', 'minecraft');

        $this->assertSame(0, $minecraft['counters']['servers']);

        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])->assertCreated();

        // These counters are denormalized and refreshed on a schedule. The one
        // number an owner checks after adding a server cannot wait for it — and
        // the API caches them too, so that has to be dropped as well.
        $after = collect($this->getJson('/api/games')->json('data'))->firstWhere('slug', 'minecraft');

        $this->assertSame(1, $after['counters']['servers']);
        $this->assertSame(1, $after['counters']['servers_online']);
        $this->assertSame(42, $after['counters']['players_online']);
    }

    /**
     * One tag, because one thing is cached: the catalog behind the rail, where
     * a game shows up once it has a server. The listing this server just joined
     * and its own page are read per request, so they have it already.
     */
    public function test_it_tells_the_frontend_which_cached_pages_are_now_wrong(): void
    {
        config()->set('services.frontend.revalidate_url', 'http://frontend.test/api/revalidate');
        config()->set('services.frontend.revalidate_secret', 'shared-secret');
        Http::fake();

        $this->driverReturns(new QueryResult(playersOnline: 42, playersMax: 200, motd: 'Nordic Survival'));

        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])->assertCreated();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://frontend.test/api/revalidate'
                && $request->header('x-revalidate-secret') === ['shared-secret']
                && $request['tags'] === ['games'];
        });
    }

    public function test_a_frontend_that_cannot_be_reached_does_not_fail_the_submission(): void
    {
        config()->set('services.frontend.revalidate_url', 'http://frontend.test/api/revalidate');
        config()->set('services.frontend.revalidate_secret', 'shared-secret');
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->driverReturns(new QueryResult(playersOnline: 42, playersMax: 200, motd: 'Nordic Survival'));

        // The server is added either way; only its freshness falls back to the
        // cache window, which is where it was before any of this existed.
        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])->assertCreated();

        $this->assertSame(1, Server::count());
    }

    public function test_the_port_may_be_left_off_and_falls_back_to_the_game_default(): void
    {
        $this->driverReturns(new QueryResult(motd: 'Bare host'));

        $this->postJson('/api/games/rust/servers', ['address' => '8.8.8.8'])->assertCreated();

        $this->assertSame(
            Game::where('slug', 'rust')->value('default_port'),
            Server::firstOrFail()->port,
        );
    }

    public function test_a_separate_query_port_is_stored_and_used_for_the_check(): void
    {
        $queried = null;

        $this->driverReturns(new QueryResult(motd: 'Split ports'), function (Server $probe) use (&$queried) {
            $queried = $probe->queryPort();
        });

        $this->postJson('/api/games/rust/servers', [
            'address' => '8.8.8.8:28015',
            'query_port' => 28016,
        ])->assertCreated();

        $this->assertSame(28016, $queried);
        $this->assertSame(28016, Server::firstOrFail()->query_port);
    }

    public function test_a_server_that_does_not_answer_is_not_written_at_all(): void
    {
        $this->driverFails();

        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');

        $this->assertSame(0, Server::withTrashed()->count());
    }

    /**
     * The four ways a query fails send an owner to four different places, and
     * the message has to say which. The one that matters is Silent over TCP: the
     * old text told them to check the query port, which is the one thing already
     * proven fine — the port accepted the connection.
     */
    public function test_a_silent_but_open_port_is_not_reported_as_a_port_problem(): void
    {
        $this->driverFailsWith(QueryFailed::timedOut('8.8.8.8:25565'));

        $error = $this->submitAndReadError('minecraft', '8.8.8.8:25565');

        $this->assertStringContainsString('accepted the connection', $error);
        $this->assertStringContainsString('not a port problem', $error);
        $this->assertStringNotContainsString('second field', $error);
    }

    public function test_silence_over_udp_stays_vague_because_it_has_to(): void
    {
        // A2S has no handshake to accept, so we cannot claim the port is open.
        $this->driverFailsWith(QueryFailed::timedOut('8.8.8.8:28015'));

        $error = $this->submitAndReadError('rust', '8.8.8.8:28015');

        $this->assertStringNotContainsString('accepted the connection', $error);
        $this->assertStringContainsString('No answer', $error);
    }

    public function test_a_closed_port_says_so(): void
    {
        $this->driverFailsWith(QueryFailed::unreachable('8.8.8.8:25565', 'Connection refused'));

        $this->assertStringContainsString(
            'Nothing is listening',
            $this->submitAndReadError('minecraft', '8.8.8.8:25565'),
        );
    }

    public function test_an_answer_we_cannot_read_points_at_the_wrong_address(): void
    {
        $this->driverFailsWith(QueryFailed::malformed('status response is not a JSON object'));

        $this->assertStringContainsString(
            'proxy or a web panel',
            $this->submitAndReadError('minecraft', '8.8.8.8:25565'),
        );
    }

    public function test_an_address_that_cannot_be_parsed_is_rejected_before_anything_is_queried(): void
    {
        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldNotReceive('for');
        $this->instance(ServerQueryManager::class, $manager);

        foreach (['not an address', 'localhost:25565', '8.8.8.8:99999', ''] as $address) {
            $this->postJson('/api/games/minecraft/servers', ['address' => $address])
                ->assertStatus(422)
                ->assertJsonValidationErrors('address');
        }
    }

    /**
     * The FiveM driver speaks HTTP, so an unchecked hostname here would let the
     * form point our monitor at an internal address.
     */
    public function test_private_and_loopback_addresses_are_refused(): void
    {
        $this->driverReturns(new QueryResult(motd: 'Should never be reached'));

        foreach (['127.0.0.1:25565', '10.0.0.4:25565', '192.168.1.10:25565'] as $address) {
            $this->postJson('/api/games/minecraft/servers', ['address' => $address])
                ->assertStatus(422)
                ->assertJsonValidationErrors('address');
        }

        $this->assertSame(0, Server::count());
    }

    public function test_an_address_already_in_the_catalog_comes_back_with_the_existing_listing(): void
    {
        $existing = Server::factory()->create([
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'host' => 'play.example.com',
            'ip_address' => '8.8.8.8',
            'port' => 25565,
            'slug' => 'already-here',
        ]);

        $this->driverReturns(new QueryResult(motd: 'Should never be reached'));

        // Found by hostname, and by the IP discovery recorded it under.
        foreach (['play.example.com:25565', '8.8.8.8:25565'] as $address) {
            $this->postJson('/api/games/minecraft/servers', ['address' => $address])
                ->assertStatus(409)
                ->assertJsonPath('data.slug', 'already-here');
        }

        $this->assertSame(1, Server::count());
        $this->assertSame($existing->name, Server::firstOrFail()->name);
    }

    public function test_a_removed_server_is_restored_rather_than_listed_twice(): void
    {
        $server = Server::factory()->create([
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'host' => '8.8.8.8',
            'port' => 25565,
            'slug' => 'second-life',
        ]);
        $server->delete();

        $this->driverReturns(new QueryResult(motd: 'Back online'));

        $this->postJson('/api/games/minecraft/servers', ['address' => '8.8.8.8:25565'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'second-life');

        $this->assertSame(1, Server::withTrashed()->count());
        $this->assertNull(Server::withTrashed()->firstOrFail()->deleted_at);
        // Its slug is a public URL and its history is worth keeping, so the
        // name it was listed under is not rewritten either.
        $this->assertSame($server->name, Server::firstOrFail()->name);
    }

    public function test_submissions_to_an_inactive_game_are_not_accepted(): void
    {
        Game::where('slug', 'fivem')->update(['is_active' => false]);

        $this->postJson('/api/games/fivem/servers', ['address' => '8.8.8.8:30120'])
            ->assertNotFound();
    }

    /** @param  callable(Server): void|null  $inspect */
    private function driverReturns(QueryResult $result, ?callable $inspect = null): void
    {
        $this->useDriver(new class($result, $inspect) implements ServerQueryDriver
        {
            public function __construct(private QueryResult $result, private $inspect) {}

            public function query(Server $server): QueryResult
            {
                ($this->inspect ?? fn () => null)($server);

                return $this->result;
            }
        });
    }

    private function driverFails(): void
    {
        $this->driverFailsWith(QueryFailed::timedOut('8.8.8.8:25565'));
    }

    private function driverFailsWith(QueryFailed $failure): void
    {
        $this->useDriver(new class($failure) implements ServerQueryDriver
        {
            public function __construct(private QueryFailed $failure) {}

            public function query(Server $server): QueryResult
            {
                throw $this->failure;
            }
        });
    }

    /** The sentence the form puts under the address field. */
    private function submitAndReadError(string $game, string $address): string
    {
        return $this->postJson("/api/games/{$game}/servers", ['address' => $address])
            ->assertStatus(422)
            ->json('errors.address.0');
    }

    private function useDriver(ServerQueryDriver $driver): void
    {
        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldReceive('for')->andReturn($driver);

        $this->instance(ServerQueryManager::class, $manager);
    }
}
