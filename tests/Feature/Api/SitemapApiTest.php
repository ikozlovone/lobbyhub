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
 * The enumeration the sitemap is built from.
 *
 * Separate from the catalog listing because it answers a different question —
 * "every URL there is" rather than "the page somebody is looking at" — and the
 * limits that make the listing safe are the ones that would make this wrong.
 */
class SitemapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_lists_every_verified_server(): void
    {
        $this->server(['slug' => 'one']);
        $this->server(['slug' => 'two']);

        $response = $this->getJson('/api/sitemap/servers')->assertOk();

        $this->assertSame(['one', 'two'], collect($response->json('data'))->pluck('slug')->sort()->values()->all());
        $this->assertSame(2, $response->json('meta.total'));
    }

    /**
     * A row discovery has written but the monitor has never reached is not in
     * any listing on the site — scopeVerified keeps it out until our own query
     * confirms it exists. Submitting it would be asking for an orphaned page to
     * be indexed.
     */
    public function test_it_leaves_out_servers_the_monitor_has_never_reached(): void
    {
        $this->server(['slug' => 'reached']);
        $this->server(['slug' => 'never', 'status' => ServerStatus::Unknown, 'last_queried_at' => null]);

        $response = $this->getJson('/api/sitemap/servers')->assertOk();

        $this->assertSame(['reached'], collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_it_leaves_out_delisted_servers(): void
    {
        $this->server(['slug' => 'listed']);
        $this->server(['slug' => 'delisted', 'is_active' => false]);

        $response = $this->getJson('/api/sitemap/servers')->assertOk();

        $this->assertSame(['listed'], collect($response->json('data'))->pluck('slug')->all());
    }

    /**
     * The whole reason this endpoint exists rather than the catalog listing:
     * that one caps at a hundred pages of a hundred rows, and a sitemap built
     * on it would stop at ten thousand servers without saying so.
     */
    public function test_it_takes_a_page_size_the_listing_would_refuse(): void
    {
        $this->server();

        $this->getJson('/api/sitemap/servers?per_page=25000')->assertOk();
        $this->getJson('/api/servers?per_page=25000')->assertStatus(422);
    }

    public function test_it_pages_without_repeating_or_dropping_a_server(): void
    {
        foreach (range(1, 5) as $i) {
            $this->server(['slug' => "server-{$i}"]);
        }

        $first = $this->getJson('/api/sitemap/servers?per_page=2&page=1')->assertOk();
        $second = $this->getJson('/api/sitemap/servers?per_page=2&page=2')->assertOk();
        $third = $this->getJson('/api/sitemap/servers?per_page=2&page=3')->assertOk();

        $slugs = collect([$first, $second, $third])
            ->flatMap(fn ($response) => collect($response->json('data'))->pluck('slug'))
            ->all();

        $this->assertCount(5, $slugs);
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        $this->assertSame(3, $first->json('meta.last_page'));
    }

    /**
     * lastmod has to mean something changed, and a monitor writes to these rows
     * every few minutes. A field that moves constantly across twenty thousand
     * URLs is not a signal, and one a crawler catches lying is one it stops
     * reading.
     */
    public function test_the_last_modified_date_ignores_the_polling(): void
    {
        $server = $this->server([
            // Written down a month ago, so the fallback is not what wins here.
            'created_at' => now()->subMonth(),
            'wiped_at' => now()->subDays(3),
            'details_synced_at' => now()->subDay(),
        ]);

        $before = $this->getJson('/api/sitemap/servers')->json('data.0.lastmod');

        $this->assertSame($server->details_synced_at->toIso8601String(), $before);

        // A poll lands: every timestamp the monitor owns moves.
        $server->forceFill(['last_queried_at' => now(), 'players_online' => 80])->save();

        $this->assertSame($before, $this->getJson('/api/sitemap/servers')->json('data.0.lastmod'));
    }

    public function test_the_last_modified_date_follows_a_wipe(): void
    {
        $server = $this->server([
            'created_at' => now()->subMonth(),
            'details_synced_at' => now()->subDays(5),
        ]);

        $wipe = now()->subHour()->startOfSecond();
        $server->forceFill(['wiped_at' => $wipe])->save();

        $this->assertSame(
            $wipe->toIso8601String(),
            $this->getJson('/api/sitemap/servers')->json('data.0.lastmod'),
        );
    }

    /** Nothing else to go on: the row still has the day it was written down. */
    public function test_a_server_with_no_history_falls_back_to_when_it_appeared(): void
    {
        $server = $this->server(['wiped_at' => null, 'details_synced_at' => null]);

        $this->assertSame(
            $server->created_at->toIso8601String(),
            $this->getJson('/api/sitemap/servers')->json('data.0.lastmod'),
        );
    }

    public function test_it_refuses_a_page_size_past_the_ceiling(): void
    {
        $this->getJson('/api/sitemap/servers?per_page=50001')->assertStatus(422);
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
