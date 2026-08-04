<?php

namespace Tests\Feature\Admin;

use App\Enums\QueryProtocol;
use App\Models\Game;
use App\Models\GameMode;
use App\Models\GameVersion;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog screens talk to the frontend after every write; nothing
        // here is testing that request except the one test that is.
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_the_list_shows_every_game_with_its_facet_counts(): void
    {
        $game = $this->game(['name' => 'Rust', 'slug' => 'rust']);
        $game->modes()->create(['slug' => 'pve', 'name' => 'PvE']);
        $this->game(['name' => 'Valheim', 'slug' => 'valheim', 'is_active' => false]);

        $response = $this->get('/admin/games')->assertOk();

        $response->assertSee('Rust');
        $response->assertSee('Valheim');
        $response->assertSee('hidden');
        $response->assertViewHas('totals', fn (array $totals) => $totals['games'] === 2
            && $totals['active'] === 1
            && $totals['hidden'] === 1);
        $this->assertSame(1, $response->viewData('games')->firstWhere('slug', 'rust')->modes_count);
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $this->game(['name' => 'Rust', 'slug' => 'rust']);
        $this->game(['name' => 'Valheim', 'slug' => 'valheim', 'is_active' => false]);
        $this->game(['name' => 'Minecraft', 'slug' => 'minecraft', 'query_protocol' => QueryProtocol::Minecraft]);

        $this->get('/admin/games?state=hidden')->assertOk()
            ->assertSee('Valheim')->assertDontSee('>Rust<', false);

        $this->get('/admin/games?protocol=minecraft')->assertOk()
            ->assertSee('Minecraft')->assertDontSee('>Rust<', false);

        $this->get('/admin/games?q=val')->assertOk()
            ->assertSee('Valheim')->assertDontSee('>Rust<', false);
    }

    public function test_a_game_can_be_created_with_every_field(): void
    {
        $response = $this->post('/admin/games', $this->fields([
            'slug' => 'soulmask',
            'name' => 'Soulmask',
            'short_name' => 'SM',
            'aliases' => "соулмаск\n  \nsoul mask",
            'steam_appid' => '2646460',
            'query_protocol' => 'source',
            'default_port' => '8777',
            'default_query_port' => '27015',
            'sort_order' => '260',
            'accent_color' => '#8B6B3B',
            'icon_path' => 'images/games/soulmask.jpg',
            'cover_path' => 'images/games/soulmask-cover.jpg',
            'description' => 'Tribal survival.',
            'meta_title' => 'Soulmask servers',
            'meta_description' => 'Soulmask server list.',
            'has_versions' => '1',
        ]));

        $game = Game::where('slug', 'soulmask')->firstOrFail();
        $response->assertRedirect("/admin/games/{$game->slug}");

        $this->assertSame('Soulmask', $game->name);
        $this->assertSame('SM', $game->short_name);
        // Blank lines are dropped and the rest are trimmed: a textarea is the
        // editor, a clean list is what search reads.
        $this->assertSame(['соулмаск', 'soul mask'], $game->aliases);
        $this->assertSame(2646460, $game->steam_appid);
        $this->assertSame(QueryProtocol::Source, $game->query_protocol);
        $this->assertSame(8777, $game->default_port);
        $this->assertSame(27015, $game->default_query_port);
        $this->assertSame(260, $game->sort_order);
        $this->assertSame('#8B6B3B', $game->accent_color);
        $this->assertSame('images/games/soulmask.jpg', $game->icon_path);
        $this->assertSame('Tribal survival.', $game->description);
        $this->assertTrue($game->is_active);
        $this->assertTrue($game->has_versions);
    }

    public function test_links_are_saved_in_the_order_they_were_typed(): void
    {
        $game = $this->game(['slug' => 'fivem']);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'fivem',
            'links' => [
                ['name' => 'FiveM Official', 'url' => 'https://fivem.net/'],
                // A blank spare row, which is how another link gets added.
                ['name' => '', 'url' => ''],
                ['name' => 'FiveM Docs', 'url' => 'https://docs.fivem.net/'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([
            ['name' => 'FiveM Official', 'url' => 'https://fivem.net/'],
            ['name' => 'FiveM Docs', 'url' => 'https://docs.fivem.net/'],
        ], $game->refresh()->links);
    }

    public function test_a_link_needs_both_halves_and_a_real_address(): void
    {
        $game = $this->game(['slug' => 'fivem']);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'fivem',
            'links' => [['name' => 'FiveM Docs', 'url' => '']],
        ]))->assertSessionHasErrors('links.0.url');

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'fivem',
            'links' => [['name' => 'FiveM Docs', 'url' => 'docs.fivem.net']],
        ]))->assertSessionHasErrors('links.0.url');

        $this->assertNull($game->refresh()->links);
    }

    public function test_clearing_a_row_removes_the_link(): void
    {
        $game = $this->game([
            'slug' => 'fivem',
            'links' => [['name' => 'FiveM Docs', 'url' => 'https://docs.fivem.net/']],
        ]);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'fivem',
            'links' => [['name' => '', 'url' => '']],
        ]))->assertSessionHasNoErrors();

        // Null rather than an empty array, so it matches a game that never had
        // any — the frontend asks one question, not two.
        $this->assertNull($game->refresh()->links);
    }

    public function test_a_slug_has_to_be_free_and_url_shaped(): void
    {
        $this->game(['slug' => 'rust']);

        $this->post('/admin/games', $this->fields(['slug' => 'rust']))
            ->assertSessionHasErrors('slug');

        $this->post('/admin/games', $this->fields(['slug' => 'Rust Console']))
            ->assertSessionHasErrors('slug');

        // Its own slug is not a collision with itself.
        $game = $this->game(['slug' => 'valheim']);
        $this->put("/admin/games/{$game->slug}", $this->fields(['slug' => 'valheim', 'name' => 'Valheim']))
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_game_saves_its_modes_and_versions_in_the_same_request(): void
    {
        $game = $this->game(['slug' => 'rust', 'name' => 'Rust']);
        $mode = $game->modes()->create(['slug' => 'pve', 'name' => 'PvE', 'sort_order' => 0]);
        $doomed = $game->modes()->create(['slug' => 'typo', 'name' => 'Typo']);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'rust',
            'name' => 'Rust',
            'modes' => [
                // Renamed and reordered.
                ['id' => $mode->id, 'slug' => 'pve', 'name' => 'PvE only', 'sort_order' => '20', 'is_active' => '1'],
                // Ticked for deletion.
                ['id' => $doomed->id, 'slug' => 'typo', 'name' => 'Typo', 'delete' => '1'],
                // A blank template row, which is not an error.
                ['slug' => '', 'name' => ''],
                // Added, with the fields that only show under the fold.
                ['slug' => 'modded', 'name' => 'Modded', 'sort_order' => '30', 'is_active' => '0',
                    'meta_title' => 'Modded Rust servers'],
            ],
            'versions' => [
                ['slug' => '1-21', 'name' => '1.21', 'released_at' => '2026-06-01', 'sort_order' => '10', 'is_active' => '1'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('PvE only', $mode->refresh()->name);
        $this->assertSame(20, $mode->sort_order);
        $this->assertNull(GameMode::find($doomed->id));

        $added = $game->modes()->where('slug', 'modded')->firstOrFail();
        $this->assertSame('Modded Rust servers', $added->meta_title);
        $this->assertFalse($added->is_active);
        $this->assertSame(2, $game->modes()->count());

        $version = $game->versions()->firstOrFail();
        $this->assertSame('1.21', $version->name);
        $this->assertSame('2026-06-01', $version->released_at->toDateString());
    }

    public function test_two_rows_cannot_claim_the_same_facet_slug(): void
    {
        $game = $this->game(['slug' => 'rust']);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'rust',
            'modes' => [
                ['slug' => 'pve', 'name' => 'PvE'],
                ['slug' => 'pve', 'name' => 'PvE again'],
            ],
        ]))->assertSessionHasErrors('modes.1.slug');

        $this->assertSame(0, $game->modes()->count());
    }

    public function test_a_half_filled_facet_row_is_refused(): void
    {
        $game = $this->game(['slug' => 'rust']);

        $this->put("/admin/games/{$game->slug}", $this->fields([
            'slug' => 'rust',
            'modes' => [['slug' => 'pve', 'name' => '']],
        ]))->assertSessionHasErrors('modes.0.name');
    }

    /**
     * The rail carries the name and the icon, so an edit has to reach it. The
     * game's own page does not need telling — it is read when a visitor asks
     * for it — which is why one tag now covers what used to take three,
     * including the old slug of a game that was just renamed.
     */
    public function test_saving_tells_the_frontend_to_drop_what_it_cached(): void
    {
        config([
            'services.frontend.revalidate_url' => 'https://front.test/api/revalidate',
            'services.frontend.revalidate_secret' => 'shhh',
        ]);

        $game = $this->game(['slug' => 'rust', 'name' => 'Rust']);

        $this->put("/admin/games/{$game->slug}", $this->fields(['slug' => 'rust-console', 'name' => 'Rust Console']))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->url() === 'https://front.test/api/revalidate'
            && $request['tags'] === ['games']);
    }

    public function test_saving_drops_the_api_response_the_edit_replaced(): void
    {
        $game = $this->game(['slug' => 'rust', 'name' => 'Rust']);

        // What a visitor would be served: the catalog as it was a moment ago.
        $this->get('/api/games')->assertOk()->assertSee('Rust');
        $this->get("/api/games/{$game->slug}")->assertOk();
        $this->assertNotNull(Cache::get('api:games'));

        $this->put("/admin/games/{$game->slug}", $this->fields(['slug' => 'rust', 'name' => 'Rust Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Cache::get('api:games'));
        $this->assertNull(Cache::get("api:games:{$game->id}"));
        $this->get('/api/games')->assertOk()->assertSee('Rust Renamed');
    }

    public function test_a_game_with_servers_cannot_be_deleted(): void
    {
        $game = $this->game(['slug' => 'rust']);
        Server::factory()->create(['game_id' => $game->id]);

        $this->delete("/admin/games/{$game->slug}")->assertSessionHasErrors('delete');
        $this->assertNotNull(Game::find($game->id));

        // The edit screen does not offer the button either.
        $this->get("/admin/games/{$game->slug}")->assertOk()->assertDontSee('Delete this game');
    }

    public function test_an_empty_game_can_be_deleted_with_its_facets(): void
    {
        $game = $this->game(['slug' => 'rend', 'name' => 'Rend']);
        $game->modes()->create(['slug' => 'pve', 'name' => 'PvE']);
        $game->versions()->create(['slug' => '1-0', 'name' => '1.0']);

        $this->delete("/admin/games/{$game->slug}")->assertRedirect('/admin/games');

        $this->assertNull(Game::find($game->id));
        $this->assertSame(0, GameMode::where('game_id', $game->id)->count());
        $this->assertSame(0, GameVersion::where('game_id', $game->id)->count());
    }

    /**
     * A valid form body, with anything the test cares about layered on top.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'a-game',
            'name' => 'A Game',
            'query_protocol' => 'source',
            'default_port' => '27015',
            'sort_order' => '500',
            'is_active' => '1',
        ];
    }

    private function game(array $attributes = []): Game
    {
        return Game::create($attributes + [
            'slug' => 'a-game',
            'name' => 'A Game',
            'query_protocol' => QueryProtocol::Source,
            'default_port' => 27015,
            'sort_order' => 500,
            'is_active' => true,
        ]);
    }
}
