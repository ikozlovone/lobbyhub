<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\Catalog\ServerListing;
use Illuminate\Console\Command;

/**
 * Pre-compute the facet counts every game page filters by.
 *
 * The alternative is what used to happen: the first visitor after both caches
 * expired paid the aggregate over the whole game. On Counter-Strike 2 that was
 * three and a half seconds on cold Postgres, and the two caches in front were
 * an argument about how *often* to make somebody pay it rather than whether.
 *
 * The columns removed the "whether". This command keeps them fresh — one pass
 * over the active catalog, one aggregate per game, straight into `games.facets`
 * — so `GameController::show` reads them as a plain JSON column instead of
 * running the five queries under the visitor's request. `facets_synced_at` is
 * how a monitor tells whether the pass is still running.
 */
class RefreshFacets extends Command
{
    protected $signature = 'facets:refresh
        {--game= : Slug of a single game; omit to refresh every active game}';

    protected $description = 'Recompute the facet chip counts every game page filters by';

    public function handle(ServerListing $listing): int
    {
        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->where('is_active', true)->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->info('No games to refresh.');

            return self::SUCCESS;
        }

        foreach ($games as $game) {
            $started = microtime(true);
            $facets = $listing->facets($game);
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            $game->forceFill([
                'facets' => $facets,
                'facets_synced_at' => now(),
            ])->save();

            $this->line(sprintf(
                '  %-30s %5d ms  %3d modes  %3d versions  %3d countries  %3d maps',
                $game->slug,
                $elapsed,
                count($facets['modes'] ?? []),
                count($facets['versions'] ?? []),
                count($facets['countries'] ?? []),
                count($facets['maps'] ?? []),
            ));
        }

        $this->info(sprintf('%d game(s) refreshed.', $games->count()));

        return self::SUCCESS;
    }
}
