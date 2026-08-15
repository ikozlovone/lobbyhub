<?php

namespace App\Console\Commands;

use App\Jobs\SyncSteamGame;
use App\Models\Game;
use App\Services\Discovery\SteamCatalogSync;
use App\Services\Discovery\SteamServerSweep;
use Illuminate\Console\Command;
use RuntimeException;

class SyncSteamServers extends Command
{
    protected $signature = 'steam:sync
        {--game= : Slug of a single game; omit to sweep every game with an app id}
        {--populated : Only servers with players on them — the cheap, frequent pass}
        {--sync : Run the sweeps inline instead of queueing them}';

    protected $description = 'Read every Source server Steam knows about and write it into the catalog';

    /**
     * The bulk half of monitoring.
     *
     * Steam's own server list carries everything a query would tell us —
     * players, map, version, bots, anti-cheat, the tag string with the queue and
     * the wipe — for every registered server of a game, in a few requests. What
     * it cannot give is a latency measurement, and what it cannot answer is
     * whether a server that is absent is switched off or simply not registered.
     * So the per-server queries stay, aimed only at the servers this does not
     * reach: see DispatchServerQueries.
     */
    public function handle(SteamServerSweep $sweep, SteamCatalogSync $sync): int
    {
        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->whereNotNull('steam_appid')->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->error('No games to sweep.');

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            foreach ($games as $game) {
                if ($game->steam_appid !== null) {
                    SyncSteamGame::dispatch($game, (bool) $this->option('populated'));
                }
            }

            $this->info(sprintf(
                '%d game(s) offered to the queue%s.',
                $games->count(),
                $this->option('populated') ? ' (occupied servers only)' : '',
            ));

            return self::SUCCESS;
        }

        $totals = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requests' => 0, 'truncated' => 0, 'unreachable' => 0];
        $spent = ['totalMs' => 0.0, 'steamMs' => 0.0, 'rowsMs' => 0.0, 'dbMs' => 0.0, 'existingMs' => 0.0];

        foreach ($games as $game) {
            if ($game->steam_appid === null) {
                $this->warn("  {$game->slug}: no Steam app id, skipped");

                continue;
            }

            try {
                $report = $sync->run($game, $sweep, (bool) $this->option('populated'));
            } catch (RuntimeException $exception) {
                $this->error("  {$game->slug}: {$exception->getMessage()}");

                continue;
            }

            foreach (['found', 'created', 'updated', 'skipped', 'requests', 'truncated', 'unreachable'] as $key) {
                $totals[$key] += $report->{$key};
            }

            foreach (array_keys($spent) as $key) {
                $spent[$key] += $report->{$key};
            }

            $this->line(sprintf(
                '  %-30s %8.0f ms  %6d found  %5d new  %5d updated  %5d skipped  %5d sampled  %3d requests',
                $game->slug,
                $report->totalMs,
                $report->found,
                $report->created,
                $report->updated,
                $report->skipped,
                $report->sampled,
                $report->requests,
            ));

            /*
             * The breakdown under the line, because the total on its own does
             * not say what to do about itself. `steam` is the wait on the API
             * and only more keys or more concurrency move it; `rows` is decode
             * and build; `db` is the writes; `existing` is the address map,
             * which grows with the catalog rather than with the game.
             */
            $this->line(sprintf(
                '      <fg=gray>steam %.0f ms   rows %.0f ms   db %.0f ms   existing %.0f ms</>',
                $report->steamMs,
                $report->rowsMs,
                $report->dbMs,
                $report->existingMs,
            ));

            /*
             * Said out loud rather than left in the totals. A truncated bucket
             * is servers Steam has and would not hand over even after every
             * axis had been applied, and the number of them is the one thing on
             * this screen that means the catalog is incomplete.
             */
            if ($report->truncated > 0) {
                $this->warn("    {$report->truncated} bucket(s) still full after every filter — some servers were not reached");
            }

            // A different gap with a different fix: the first is Steam refusing
            // to finish, this is the network refusing to carry it.
            if ($report->unreachable > 0) {
                $this->warn("    {$report->unreachable} bucket(s) could not be fetched at all — network or Steam was unreachable");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%.1f s total — steam %.1f s, rows %.1f s, db %.1f s, existing %.1f s.',
            $spent['totalMs'] / 1000,
            $spent['steamMs'] / 1000,
            $spent['rowsMs'] / 1000,
            $spent['dbMs'] / 1000,
            $spent['existingMs'] / 1000,
        ));
        $this->info(sprintf(
            '%d found, %d new, %d updated%s, %d requests%s.',
            $totals['found'],
            $totals['created'],
            $totals['updated'],
            $totals['skipped'] > 0 ? ", {$totals['skipped']} skipped" : '',
            $totals['requests'],
            $totals['truncated'] > 0 ? ", {$totals['truncated']} truncated" : '',
        ));

        return self::SUCCESS;
    }
}
