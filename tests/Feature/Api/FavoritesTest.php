<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_the_list_is_private(): void
    {
        $this->getJson('/api/favorites')->assertUnauthorized();
        $this->postJson('/api/servers/'.$this->server('rust')->slug.'/favorite')->assertUnauthorized();
    }

    public function test_a_visitor_only_ever_sees_their_own(): void
    {
        $mine = $this->server('rust', ['name' => 'My Server']);
        $theirs = $this->server('rust', ['name' => 'Their Server']);

        $me = User::factory()->create();
        $me->favorites()->attach($mine, ['created_at' => now()]);
        User::factory()->create()->favorites()->attach($theirs, ['created_at' => now()]);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/favorites')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('My Server', $response->json('data.0.servers.0.name'));
    }

    public function test_servers_are_grouped_into_blocks_by_game(): void
    {
        $user = User::factory()->create();

        $user->favorites()->attach($this->server('minecraft', ['name' => 'MC One']), ['created_at' => now()->subHour()]);
        $user->favorites()->attach($this->server('minecraft', ['name' => 'MC Two']), ['created_at' => now()]);
        $user->favorites()->attach($this->server('rust', ['name' => 'Rust One']), ['created_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/favorites')->assertOk();

        // Games in the catalog's own order — Minecraft sorts above Rust — and
        // inside a game, the newest star first.
        $this->assertSame(['minecraft', 'rust'], $response->json('data.*.game.slug'));
        $this->assertSame(['MC Two', 'MC One'], $response->json('data.0.servers.*.name'));
        $this->assertSame('Rust One', $response->json('data.1.servers.0.name'));

        // The block carries what it takes to draw a heading, once per game.
        $this->assertSame('Minecraft', $response->json('data.0.game.name'));
        $this->assertSame('minecraft', $response->json('data.0.game.protocol'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_starring_the_same_server_twice_is_one_star(): void
    {
        $server = $this->server('rust');
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/servers/{$server->slug}/favorite")->assertCreated();
        $this->postJson("/api/servers/{$server->slug}/favorite")->assertCreated();

        $this->assertSame(1, $user->favorites()->count());
    }

    public function test_a_star_can_be_taken_back(): void
    {
        $server = $this->server('rust');
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/servers/{$server->slug}/favorite")->assertCreated();
        $this->deleteJson("/api/servers/{$server->slug}/favorite")
            ->assertOk()
            ->assertJsonPath('data.favorited', false);

        $this->assertSame(0, $user->favorites()->count());
        // Un-starring something that was never starred is the state asked for.
        $this->deleteJson("/api/servers/{$server->slug}/favorite")->assertOk();
    }

    /**
     * A delisted server keeps its stars but leaves the list: it comes back with
     * them if it is listed again, and nobody has to notice it went.
     */
    public function test_a_delisted_server_drops_out_of_the_list(): void
    {
        $server = $this->server('rust');
        $user = User::factory()->create();
        $user->favorites()->attach($server, ['created_at' => now()]);

        Sanctum::actingAs($user);
        $this->assertSame(1, $this->getJson('/api/favorites')->json('meta.total'));

        $server->forceFill(['is_active' => false])->save();
        $this->assertSame(0, $this->getJson('/api/favorites')->json('meta.total'));
        $this->assertSame(1, $user->favorites()->count());

        $server->forceFill(['is_active' => true])->save();
        $this->assertSame(1, $this->getJson('/api/favorites')->json('meta.total'));
    }

    public function test_the_list_is_never_served_from_a_cache(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->assertSame(0, $this->getJson('/api/favorites')->json('meta.total'));

        $user->favorites()->attach($this->server('rust'), ['created_at' => now()]);

        // The same request a second later, with no cache to wait out.
        $this->assertSame(1, $this->getJson('/api/favorites')->json('meta.total'));
    }

    public function test_a_server_that_is_gone_cannot_be_starred(): void
    {
        $server = $this->server('rust', ['is_active' => false]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/servers/{$server->slug}/favorite")->assertNotFound();
    }

    private function server(string $game, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', $game)->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
