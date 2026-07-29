<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->server->forceFill(['last_queried_at' => now()->subSeconds(5)])->save();
        $this->fakeDriver(new QueryResult(playersOnline: 999, playersMax: 999));

        $response = $this->postJson('/api/servers/refresh-me/refresh')->assertOk();

        // Answered, but without knocking: the cooldown is there to protect an
        // address we do not own from however many people press the button.
        $this->assertFalse($response->json('refreshed'));
        $this->assertSame(10, $response->json('data.live.players'));
        $this->assertSame(10, $this->server->refresh()->players_online);
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

    /** Null stands for a server that did not answer. */
    private function fakeDriver(?QueryResult $result): void
    {
        $driver = new class($result) implements ServerQueryDriver
        {
            public function __construct(private ?QueryResult $result) {}

            public function query(Server $server): QueryResult
            {
                return $this->result ?? throw QueryFailed::timedOut('1.2.3.4:28015');
            }
        };

        $manager = \Mockery::mock(ServerQueryManager::class);
        $manager->shouldReceive('supports')->andReturnTrue();
        $manager->shouldReceive('for')->andReturn($driver);

        $this->app->instance(ServerQueryManager::class, $manager);
    }
}
