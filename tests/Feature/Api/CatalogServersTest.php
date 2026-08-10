<?php

namespace Tests\Feature\Api;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog-wide listing behind the home page's sections.
 *
 * What matters here is what the per-game listing cannot do: reach across games
 * in one query, and say which game each row came from.
 */
class CatalogServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_lists_servers_from_every_game_in_one_response(): void
    {
        $this->server('minecraft', ['players_online' => 40]);
        $this->server('rust', ['players_online' => 900]);

        $data = $this->getJson('/api/servers?sort=players')->assertOk()->json('data');

        $this->assertSame(['rust', 'minecraft'], array_column(array_column($data, 'game'), 'slug'));
    }

    public function test_each_row_carries_the_game_it_belongs_to(): void
    {
        $this->server('rust');

        $game = $this->getJson('/api/servers')->assertOk()->json('data.0.game');

        // The protocol is what tells the frontend whether Connect can be a
        // steam:// link, so it has to survive the trip.
        $this->assertSame(['slug' => 'rust', 'name' => 'Rust', 'protocol' => 'source'], $game);
    }

    /**
     * The listing's trimmed `game` must not reach the detail payload.
     *
     * It did: ServerDetailResource composed itself with `+`, which keeps the
     * left value, so the three-field version won and the full GameResource was
     * dropped. The page reads game.monitoring.protocol and threw in the browser
     * while the API answered 200.
     */
    public function test_the_detail_payload_keeps_the_whole_game_not_the_listing_summary(): void
    {
        $server = $this->server('rust');

        $game = $this->getJson("/api/servers/{$server->slug}")->assertOk()->json('data.game');

        $this->assertSame('source', $game['monitoring']['protocol']);
        $this->assertArrayHasKey('counters', $game);
        $this->assertArrayHasKey('default_port', $game['monitoring']);
    }

    public function test_the_per_game_listing_still_omits_the_game(): void
    {
        $this->server('rust');

        $row = $this->getJson('/api/games/rust/servers')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('game', $row);
    }

    public function test_the_wipe_filter_keeps_only_recent_wipes(): void
    {
        $this->server('rust', ['name' => 'fresh', 'wiped_at' => now()->subDays(2)]);
        $this->server('rust', ['name' => 'stale', 'wiped_at' => now()->subDays(60)]);
        $this->server('rust', ['name' => 'never', 'wiped_at' => null]);

        $data = $this->getJson('/api/servers?sort=wiped&wiped=14')->assertOk()->json('data');

        $this->assertSame(['fresh'], array_column($data, 'name'));
    }

    public function test_it_can_be_narrowed_to_one_game_and_searched(): void
    {
        $this->server('rust', ['name' => 'Rusty Moose']);
        $this->server('rust', ['name' => 'Something else']);
        $this->server('minecraft', ['name' => 'Moose Craft']);

        $byGame = $this->getJson('/api/servers?game=rust')->assertOk()->json('data');
        $this->assertCount(2, $byGame);

        // Case-folded on both sides, like the per-game listing.
        $bySearch = $this->getJson('/api/servers?q=moose')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            ['Rusty Moose', 'Moose Craft'],
            array_column($bySearch, 'name'),
        );
    }

    public function test_it_rejects_a_sort_it_does_not_offer(): void
    {
        $this->getJson('/api/servers?sort=whatever')->assertStatus(422);
    }

    /**
     * Insertion order, newest first.
     *
     * Worth pinning because this is the one sort whose column is also the
     * tiebreak every other sort ends on, and appending the tiebreak to itself
     * asked for `order by id desc, id asc` — harmless to the answer, but a
     * shape no index can be built in.
     */
    public function test_the_newest_sort_runs_from_the_last_row_backwards(): void
    {
        $first = $this->server('rust', ['name' => 'first']);
        $second = $this->server('rust', ['name' => 'second']);
        $third = $this->server('rust', ['name' => 'third']);

        $this->assertTrue($first->id < $second->id && $second->id < $third->id);

        $names = array_column(
            $this->getJson('/api/servers?sort=newest')->assertOk()->json('data'),
            'name',
        );

        $this->assertSame(['third', 'second', 'first'], $names);
    }

    private function server(string $game, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', $game)->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
