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
 * This is about the other half of the job: telling the frontend that the one
 * thing it caches — the game catalog behind its navigation rail — is no longer
 * describing the same catalog.
 *
 * Membership is what matters. A game appears in the rail once it has a server,
 * and drops out when it loses its last one; everything else about a listing is
 * read per request and needs no telling.
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
     * The rail lists only games that have servers, so a game the monitor has
     * just filled has to get into it — discovery and the monitor both happen
     * without anyone touching the site, and until this existed the only thing
     * that ever told the frontend anything was the submission form.
     */
    public function test_a_game_that_gained_a_server_is_revalidated(): void
    {
        $this->server('minecraft');

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://front.test/api/revalidate'
            && in_array('games', $request['tags'], true));
    }

    public function test_a_game_that_lost_its_last_server_is_revalidated_too(): void
    {
        $server = $this->server('rust');
        $this->artisan('counters:refresh')->assertSuccessful();
        Http::fake();

        $server->delete();

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => in_array('games', $request['tags'], true));
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
     * Player counts move every minute of every day, and the pages that show
     * them read them per request. Expiring the rail over those would mean it is
     * never cached at all — which is the entire point of caching it.
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
     * Per-game tags are gone along with the per-game caching they expired.
     * Sending them would be a call the frontend does nothing with, made from
     * inside a job that runs every minute.
     */
    public function test_no_per_game_tags_are_sent(): void
    {
        $this->server('minecraft');

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => $request['tags'] === ['games']);
    }

    /**
     * One tag covers the whole catalog, so a refresh that moved forty games is
     * the same single call as one that moved one. This used to be a batching
     * loop against the frontend route's 32-tag cap.
     */
    public function test_many_games_moving_at_once_is_still_one_call(): void
    {
        Game::query()->take(40)->get()->each(fn (Game $game) => $this->server($game->slug));

        $this->artisan('counters:refresh')->assertSuccessful();

        Http::assertSentCount(1);
    }

    private function server(string $game, array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', $game)->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
