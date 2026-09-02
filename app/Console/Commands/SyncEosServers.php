<?php

namespace App\Console\Commands;

use App\Enums\QueryProtocol;
use App\Models\Game;
use App\Services\Discovery\EosCatalogSync;
use App\Services\Discovery\EosClient;
use App\Services\Discovery\EosDeployment;
use App\Services\Discovery\EosServerSweep;
use Illuminate\Console\Command;
use RuntimeException;

class SyncEosServers extends Command
{
    protected $signature = 'eos:sync
        {--game= : Slug of a single EOS game; omit to sweep every EOS game with configured credentials}
        {--pages= : Cap on the number of pages to fetch per game (default: all)}
        {--dry-run : Fetch and count, do not write to the catalog}';

    protected $description = 'Read every EOS session for a game and write it into the catalog';

    /**
     * The bulk half of monitoring for games that never see Valve A2S.
     *
     * ARK: Survival Ascended is the case that opened this door: UE5, EAC/EOS
     * networking, no answer on any port to `\xFF\xFF\xFF\xFF T Source Engine
     * Query\0`. Epic's matchmaking hands back what a UDP query would have
     * carried — session name, map, players, version — in one paged pull, and
     * this command is what turns that into `server_states` writes.
     */
    public function handle(EosClient $client, EosServerSweep $sweep, EosCatalogSync $sync): int
    {
        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->where('query_protocol', QueryProtocol::Eos->value)->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->error('No EOS games to sweep.');

            return self::FAILURE;
        }

        $maxPages = $this->option('pages') === null ? null : (int) $this->option('pages');

        if ($this->option('dry-run')) {
            return $this->dryRun($games, $client, $maxPages);
        }

        $totals = ['found' => 0, 'distinct' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0];
        $spent = ['totalMs' => 0.0, 'httpMs' => 0.0, 'rowsMs' => 0.0, 'dbMs' => 0.0, 'existingMs' => 0.0];

        foreach ($games as $game) {
            if ($game->query_protocol !== QueryProtocol::Eos) {
                $this->warn("  {$game->slug}: not an EOS game, skipped");

                continue;
            }

            try {
                $report = $sync->run($game, $sweep, $maxPages);
            } catch (RuntimeException $exception) {
                $this->error("  {$game->slug}: {$exception->getMessage()}");

                continue;
            }

            foreach (['found', 'distinct', 'created', 'updated', 'skipped', 'pages'] as $key) {
                $totals[$key] += $report->{$key};
            }

            foreach (array_keys($spent) as $key) {
                $spent[$key] += $report->{$key};
            }

            $this->line(sprintf(
                '  %-30s %8.0f ms  %6d found  %6d distinct  %5d new  %5d updated  %5d skipped  %3d pages',
                $game->slug,
                $report->totalMs,
                $report->found,
                $report->distinct,
                $report->created,
                $report->updated,
                $report->skipped,
                $report->pages,
            ));

            $this->line(sprintf(
                '      <fg=gray>eos %.0f ms   rows %.0f ms   db %.0f ms   existing %.0f ms</>',
                $report->httpMs,
                $report->rowsMs,
                $report->dbMs,
                $report->existingMs,
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%.1f s total — eos %.1f s, rows %.1f s, db %.1f s, existing %.1f s.',
            $spent['totalMs'] / 1000,
            $spent['httpMs'] / 1000,
            $spent['rowsMs'] / 1000,
            $spent['dbMs'] / 1000,
            $spent['existingMs'] / 1000,
        ));
        $this->info(sprintf(
            '%d found, %d distinct, %d new, %d updated%s, %d pages.',
            $totals['found'],
            $totals['distinct'],
            $totals['created'],
            $totals['updated'],
            $totals['skipped'] > 0 ? ", {$totals['skipped']} skipped" : '',
            $totals['pages'],
        ));

        return self::SUCCESS;
    }

    /**
     * Fetch, count, print — do not write. The one thing this proves is that
     * the credentials work, the endpoint answers and the shape parses; the
     * numbers are what a real sync would then act on. Walks the same page
     * generator the sync does, but throws away every session it decodes.
     */
    private function dryRun($games, EosClient $client, ?int $maxPages): int
    {
        foreach ($games as $game) {
            if ($game->query_protocol !== QueryProtocol::Eos) {
                $this->warn("  {$game->slug}: not an EOS game, skipped");

                continue;
            }

            try {
                $deployment = EosDeployment::forGame($game);
            } catch (RuntimeException $exception) {
                $this->error("  {$game->slug}: {$exception->getMessage()}");

                continue;
            }

            // First page separately, so `totalCount` can be printed alongside
            // — a coverage figure the pass otherwise makes the reader guess.
            $first = null;
            try {
                $first = $client->filter($deployment, [], 0);
            } catch (RuntimeException $exception) {
                $this->error("  {$game->slug}: {$exception->getMessage()}");

                continue;
            }

            $totalCount = (int) ($first['pagination']['totalCount'] ?? 0);
            $pages = 1;
            $sessions = count($first['sessions']);
            $distinct = [];

            foreach ($first['sessions'] as $session) {
                $address = \App\Services\Discovery\DiscoveredEosServer::addressOf($session);
                if ($address !== null) {
                    $distinct[$address[0].':'.$address[1]] = true;
                }
            }

            // Rest of the walk, if the operator asked for more than one page.
            if ($maxPages === null || $maxPages > 1) {
                $remaining = $maxPages === null ? null : $maxPages - 1;

                try {
                    foreach ($client->pages($deployment, [], $remaining, $sessions) as $page) {
                        $pages++;
                        $sessions += count($page);

                        foreach ($page as $session) {
                            $address = \App\Services\Discovery\DiscoveredEosServer::addressOf($session);
                            if ($address !== null) {
                                $distinct[$address[0].':'.$address[1]] = true;
                            }
                        }
                    }
                } catch (RuntimeException $exception) {
                    $this->error("  {$game->slug}: {$exception->getMessage()}");

                    continue;
                }
            }

            $this->line(sprintf(
                '  %-30s  %d page(s), %d session(s), %d distinct address(es)%s',
                $game->slug,
                $pages,
                $sessions,
                count($distinct),
                $totalCount ? ", totalCount reported {$totalCount}" : '',
            ));
        }

        return self::SUCCESS;
    }
}
