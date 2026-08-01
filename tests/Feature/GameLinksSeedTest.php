<?php

namespace Tests\Feature;

use App\Models\Game;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded links are data, and data rots: a studio folds, a domain lapses, a
 * wiki reorganises. Nothing here can tell whether an address still answers —
 * that is a job for a person with a browser — but it can hold the shape, so a
 * typo does not reach a page as a link to nowhere.
 */
class GameLinksSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GameSeeder::class);
    }

    public function test_every_game_has_at_least_one_link(): void
    {
        $without = Game::query()->whereNull('links')->pluck('slug')->all();

        $this->assertSame([], $without, 'Games with no links: '.implode(', ', $without));
    }

    public function test_every_link_is_a_label_and_an_https_address(): void
    {
        foreach (Game::all() as $game) {
            foreach ($game->links as $link) {
                $this->assertSame(['name', 'url'], array_keys($link), "{$game->slug}: unexpected keys");
                $this->assertNotSame('', trim($link['name']), "{$game->slug}: link with no label");
                $this->assertStringStartsWith('https://', $link['url'], "{$game->slug}: {$link['url']}");
                $this->assertNotFalse(filter_var($link['url'], FILTER_VALIDATE_URL), "{$game->slug}: {$link['url']}");
            }
        }
    }

    public function test_a_game_does_not_repeat_itself(): void
    {
        foreach (Game::all() as $game) {
            $urls = array_column($game->links, 'url');

            $this->assertSame($urls, array_unique($urls), "{$game->slug}: the same address twice");
        }
    }

    /**
     * The reason this block exists at all is that a competitor's version of it
     * is an ad. Ours links to the people who made the game.
     */
    public function test_no_link_carries_somebody_elses_referral(): void
    {
        foreach (Game::all() as $game) {
            foreach ($game->links as $link) {
                $this->assertStringNotContainsString('?ads=', $link['url'], "{$game->slug}");
                $this->assertStringNotContainsString('ref=', $link['url'], "{$game->slug}");
            }
        }
    }

    /** Re-running the seeder must not stack a second copy of every link. */
    public function test_seeding_twice_leaves_the_same_links(): void
    {
        $before = Game::orderBy('slug')->pluck('links', 'slug');

        $this->seed(GameSeeder::class);

        $this->assertEquals($before, Game::orderBy('slug')->pluck('links', 'slug'));
    }
}
