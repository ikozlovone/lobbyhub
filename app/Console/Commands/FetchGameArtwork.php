<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchGameArtwork extends Command
{
    protected $signature = 'games:artwork {--force : Re-download art that is already stored}';

    protected $description = 'Download game cover art from Steam by app id';

    /**
     * Steam's own CDN, not a mirror. The header image is 460x215 — the format
     * every store-adjacent site uses for a game card.
     */
    private const HEADER_URL = 'https://cdn.cloudflare.steamstatic.com/steam/apps/%d/header.jpg';

    private const DESTINATION = 'images/games';

    public function handle(): int
    {
        $directory = public_path(self::DESTINATION);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $games = Game::query()->whereNotNull('steam_appid')->orderBy('sort_order')->get();
        $downloaded = 0;
        $failed = [];

        foreach ($games as $game) {
            $path = self::DESTINATION."/{$game->slug}.jpg";

            if (! $this->option('force') && is_file(public_path($path))) {
                $this->line("  <fg=gray>skip</>   {$game->slug} (already stored)");
                $game->forceFill(['cover_path' => $path])->save();

                continue;
            }

            $response = Http::timeout(20)->get(sprintf(self::HEADER_URL, $game->steam_appid));

            // A 404 here almost always means the app id is wrong, which is worth
            // knowing loudly — it is also the id server discovery will rely on.
            if ($response->failed()) {
                $failed[] = "{$game->slug} (appid {$game->steam_appid}, HTTP {$response->status()})";
                $this->line("  <fg=red>fail</>   {$game->slug}");

                continue;
            }

            file_put_contents(public_path($path), $response->body());
            $game->forceFill(['cover_path' => $path])->save();

            $downloaded++;
            $this->line(sprintf('  <fg=green>ok</>     %-22s %6.1f KB', $game->slug, strlen($response->body()) / 1024));
        }

        $this->newLine();
        $this->info("Downloaded {$downloaded}, already stored ".($games->count() - $downloaded - count($failed)).'.');

        if ($failed !== []) {
            $this->warn('Failed: '.implode(', ', $failed));
        }

        $withoutAppId = Game::query()->whereNull('steam_appid')->pluck('slug');

        if ($withoutAppId->isNotEmpty()) {
            $this->line("<fg=gray>Not on Steam, needs its own artwork: {$withoutAppId->implode(', ')}</>");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
