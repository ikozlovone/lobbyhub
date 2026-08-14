<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\Discovery\DiscoveredServer;
use App\Services\Discovery\SteamServerSweep;
use App\Services\Discovery\SteamServerSweepParallel;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * How long Steam takes to hand over each game's server list.
 *
 * The sweep is run for every game with a Steam app id and the servers it
 * streams are counted and discarded — no database writes, no per-server A2S,
 * only the HTTP round-trips to `GetServerList`. What comes out is the wall
 * time of the Steam side of the pipeline, per game.
 */
class TimeSteamSweep extends Command
{
    protected $signature = 'steam:time
        {--game= : Slug of a single game; omit to time every game with an app id}
        {--parallel : Use the level-parallel sweep (Http::pool) instead of the sequential one}';

    protected $description = 'Measure how long Steam takes to answer GetServerList for each game';

    public function handle(SteamServerSweep $sequential, SteamServerSweepParallel $parallel): int
    {
        $sweep = $this->option('parallel') ? $parallel : $sequential;

        $this->line(sprintf('mode: %s', $this->option('parallel') ? 'parallel' : 'sequential'));


        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->whereNotNull('steam_appid')->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->error('No games to time.');

            return self::FAILURE;
        }

        $totalElapsed = 0.0;
        $totalFound = 0;
        $totalRequests = 0;
        $totalTruncated = 0;
        $totalUnreachable = 0;
        $failed = 0;

        foreach ($games as $game) {
            if ($game->steam_appid === null) {
                $this->warn(sprintf('  %-30s no Steam app id, skipped', $game->slug));

                continue;
            }

            $found = 0;
            $started = microtime(true);

            try {
                $result = $sweep->stream($game, function (DiscoveredServer $_) use (&$found) {
                    $found++;
                });
            } catch (RuntimeException $exception) {
                $elapsed = (microtime(true) - $started) * 1000;
                $this->error(sprintf('  %-30s %8.0f ms  failed: %s', $game->slug, $elapsed, $exception->getMessage()));
                $failed++;

                continue;
            }

            $elapsed = (microtime(true) - $started) * 1000;
            $totalElapsed += $elapsed;
            $totalFound += $result->found;
            $totalRequests += $result->requests;
            $totalTruncated += $result->truncated;
            $totalUnreachable += $result->unreachable;

            $this->line(sprintf(
                '  %-30s %8.0f ms  %6d found  %3d requests%s%s',
                $game->slug,
                $elapsed,
                $result->found,
                $result->requests,
                $result->truncated > 0 ? "  {$result->truncated} truncated" : '',
                $result->unreachable > 0 ? "  {$result->unreachable} unreachable" : '',
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%.1f s total, %d found, %d requests%s%s%s.',
            $totalElapsed / 1000,
            $totalFound,
            $totalRequests,
            $totalTruncated > 0 ? ", {$totalTruncated} truncated" : '',
            $totalUnreachable > 0 ? ", {$totalUnreachable} unreachable" : '',
            $failed > 0 ? ", {$failed} failed" : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
