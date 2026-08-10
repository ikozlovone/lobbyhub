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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The header that turns the caches in front of PHP on.
 *
 * Worth its own file because of what it would mean to get wrong. nginx and
 * Cloudflare both do exactly what this header says, so a route that says
 * `public` by mistake is one whose answer is handed to the next person to ask —
 * and the routes that must never do that sit in the same group as the ones that
 * should.
 */
class PublicCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_the_public_reads_say_how_long_they_may_be_shared(): void
    {
        $server = $this->server();

        $shareable = [
            '/api/games' => 60,
            '/api/games/rust' => 60,
            '/api/games/rust/servers' => 60,
            '/api/games/rust/votes' => 60,
            '/api/servers' => 60,
            "/api/servers/{$server->slug}" => 60,
            "/api/servers/{$server->slug}/history" => 300,
            '/api/sitemap/servers' => 3600,
        ];

        foreach ($shareable as $url => $seconds) {
            $header = $this->getJson($url)->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('public', (string) $header, $url);
            $this->assertStringContainsString("s-maxage={$seconds}", (string) $header, $url);
            // The visitor's own browser keeps nothing: coming back to a listing
            // should re-read it, not show what was in the tab.
            $this->assertStringContainsString('max-age=0', (string) $header, $url);
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function privateReads(): array
    {
        return [
            // Its whole job is to be newer than the page around it.
            ['/api/servers/live?slugs=anything'],
            // Deployment configuration, but read through the auth group.
            ['/api/auth/providers'],
        ];
    }

    #[DataProvider('privateReads')]
    public function test_the_rest_is_left_unshareable(string $url): void
    {
        $header = (string) $this->getJson($url)->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $header, $url);
        $this->assertStringNotContainsString('s-maxage', $header, $url);
    }

    /** The vote status is about whoever is asking, and shares a prefix with the reads. */
    public function test_the_vote_status_is_never_shareable(): void
    {
        $server = $this->server();

        $header = (string) $this->getJson("/api/servers/{$server->slug}/vote")
            ->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $header);
    }

    /**
     * A token means the answer may be about that account, whatever the route
     * normally returns — so the header comes off even on a shareable route.
     */
    public function test_a_request_carrying_a_token_is_not_marked_shareable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $header = (string) $this->withHeader('Authorization', 'Bearer whatever')
            ->getJson('/api/games')
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $header);
    }

    /** An error is not an answer to hand the next visitor. */
    public function test_a_failure_is_not_marked_shareable(): void
    {
        $header = (string) $this->getJson('/api/games/not-a-game')
            ->assertNotFound()
            ->headers->get('Cache-Control');

        $this->assertStringNotContainsString('public', $header);
    }

    private function server(): Server
    {
        return Server::factory()->create([
            'game_id' => Game::where('slug', 'rust')->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
