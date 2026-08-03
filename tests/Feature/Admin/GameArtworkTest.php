<?php

namespace Tests\Feature\Admin;

use App\Models\Game;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The three pictures, and the admin that manages them.
 *
 * Files land in public/images/games, which is gitignored and shared with
 * `games:artwork`, so each test cleans up after the game it touched.
 */
class GameArtworkTest extends TestCase
{
    use RefreshDatabase;

    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_the_api_exposes_all_three_pictures(): void
    {
        $game = $this->rust();
        $game->forceFill([
            'icon_path' => 'images/games/rust-icon.png',
            'cover_path' => 'images/games/rust.jpg',
            'hero_path' => 'images/games/rust-hero.jpg',
        ])->save();

        $data = $this->getJson('/api/games/rust')->assertOk()->json('data');

        $this->assertStringEndsWith('images/games/rust-icon.png', $data['icon']);
        $this->assertStringEndsWith('images/games/rust.jpg', $data['cover']);
        $this->assertStringEndsWith('images/games/rust-hero.jpg', $data['hero']);
    }

    public function test_a_game_with_no_banner_reports_null_rather_than_borrowing_the_cover(): void
    {
        // The fallback is the frontend's job — the API says what is stored, or
        // there would be no way to tell a real banner from a stand-in.
        $this->rust()->forceFill(['cover_path' => 'images/games/rust.jpg', 'hero_path' => null])->save();

        $this->assertNull($this->getJson('/api/games/rust')->json('data.hero'));
    }

    public function test_uploading_stores_each_role_under_its_own_name(): void
    {
        $game = $this->rust();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => [
                'icon_path' => UploadedFile::fake()->image('whatever.png', 64, 64),
                'hero_path' => UploadedFile::fake()->image('another.jpg', 1200, 400),
            ],
        ]))->assertRedirect();

        $game->refresh();

        $this->assertSame('images/games/rust-icon.png', $game->icon_path);
        $this->assertSame('images/games/rust-hero.jpg', $game->hero_path);
        $this->assertFileExists($this->track($game->icon_path));
        $this->assertFileExists($this->track($game->hero_path));
    }

    public function test_the_uploaded_filename_is_never_used(): void
    {
        $game = $this->rust();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => ['icon_path' => UploadedFile::fake()->image('../../../evil.png', 8, 8)],
        ]))->assertRedirect();

        // Slug, role and an extension chosen from the MIME type — nothing the
        // browser sent contributes to the path.
        $this->assertSame('images/games/rust-icon.png', $game->refresh()->icon_path);
        $this->track($game->icon_path);
    }

    public function test_a_new_upload_replaces_the_file_it_supersedes(): void
    {
        $game = $this->rust();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => ['icon_path' => UploadedFile::fake()->image('one.png', 8, 8)],
        ]))->assertRedirect();

        $first = $this->track($game->refresh()->icon_path);

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => ['icon_path' => UploadedFile::fake()->image('two.jpg', 8, 8)],
        ]))->assertRedirect();

        $second = $this->track($game->refresh()->icon_path);

        $this->assertSame('images/games/rust-icon.jpg', $game->icon_path);
        $this->assertFileExists($second);
        $this->assertFileDoesNotExist($first);
    }

    public function test_removing_clears_the_column_and_deletes_the_file(): void
    {
        $game = $this->rust();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => ['hero_path' => UploadedFile::fake()->image('hero.jpg', 400, 200)],
        ]))->assertRedirect();

        $path = public_path($game->refresh()->hero_path);
        $this->assertFileExists($path);

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'remove' => ['hero_path' => '1'],
        ]))->assertRedirect();

        $this->assertNull($game->refresh()->hero_path);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removing_leaves_alone_a_file_outside_our_own_directory(): void
    {
        // Hand-typed paths may be shared with another game, or be something the
        // deploy put there. Forget the row; do not delete somebody else's file.
        $game = $this->rust();
        $game->forceFill(['hero_path' => 'images/shared/banner.jpg'])->save();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'remove' => ['hero_path' => '1'],
        ]))->assertRedirect();

        $this->assertNull($game->refresh()->hero_path);
    }

    public function test_it_refuses_a_file_that_is_not_an_image(): void
    {
        $game = $this->rust();

        $this->put(route('admin.games.update', $game), $this->form($game, [
            'artwork' => ['icon_path' => UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml')],
        ]))->assertSessionHasErrors('artwork.icon_path');

        $this->assertNull($game->refresh()->icon_path);
    }

    public function test_the_form_offers_a_control_for_every_role(): void
    {
        $response = $this->get(route('admin.games.edit', $this->rust()))->assertOk();

        foreach (['icon_path', 'cover_path', 'hero_path'] as $role) {
            $response->assertSee("artwork[{$role}]", escape: false);
        }
    }

    private function rust(): Game
    {
        return Game::where('slug', 'rust')->firstOrFail();
    }

    /** The whole form, since update() validates every required column. */
    private function form(Game $game, array $overrides): array
    {
        return array_merge([
            'name' => $game->name,
            'slug' => $game->slug,
            'query_protocol' => $game->query_protocol->value,
            'default_port' => $game->default_port,
            'sort_order' => $game->sort_order,
            'is_active' => '1',
        ], $overrides);
    }

    private function track(string $path): string
    {
        $absolute = public_path($path);
        $this->written[] = $absolute;

        return $absolute;
    }
}
