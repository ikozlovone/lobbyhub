<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The counters themselves are covered from the API's side, in CatalogApiTest.
 * This is about the other half of the job: telling the frontend that the pages
 * it has cached are no longer describing the same catalog.
 */
class CatalogCountersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        config([
            'services.frontend.revalidate_url' => 'https://front.test/api/revalidate',
            'services.frontend.revalidate_secret' => 'shhh',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * The bug: a game page prerendered while the game was empty kept saying "no
     * servers listed yet" after discovery and the monitor had filled it, because
     * the only thing that ever revalidated a listing was the submission form.
     */
    public function test_a_game_that_gained_a_server_is_revalidated(): void
    {
        $this->server('minecraft');

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://front.test/api/revalidate'
            && in_array('game:minecraft', $request['tags'], true));
    }

    public function test_a_game_that_lost_its_last_server_is_revalidated_too(): void
    {
        $server = $this->server('rust');
        $this->artisan('counters:refresh')->assertSuccessful();
        Http::fake();

        $server->delete();

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => in_array('game:rust', $request['tags'], true));
    }

    /**
     * A server discovery has written but the monitor has not reached yet is not
     * on any listing, so nothing about the page it would appear on has changed.
     */
    public function test_an_unverified_server_changes_nothing(): void
    {
        $this->server('minecraft', ['status' => ServerStatus::Unknown]);

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Player counts move every minute of every day, and the browser's live layer
     * overwrites them anyway. Expiring the shells over those would mean they are
     * never cached at all — which is the entire point of caching them.
     */
    public function test_a_player_count_moving_does_not_expire_the_page(): void
    {
        $server = $this->server('minecraft', ['players_online' => 10]);
        $this->artisan('counters:refresh')->assertSuccessful();
        Http::fake();

        $server->forceFill(['players_online' => 900])->save();

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * `games` is on every page in the catalog. Sending it from a job that runs
     * every minute would expire all of them every minute, by another route.
     */
    public function test_the_catalog_wide_tag_is_left_alone(): void
    {
        $this->server('minecraft');

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => ! in_array('games', $request['tags'], true));
    }

    /** The frontend route drops anything past 32 tags, so they go in batches. */
    public function test_more_games_than_one_call_carries_are_sent_in_batches(): void
    {
        Game::query()->take(40)->get()->each(fn (Game $game) => $this->server($game->slug));

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => count($request['tags']) <= 32);
    }

    private function server(string $game, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', $game)->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
