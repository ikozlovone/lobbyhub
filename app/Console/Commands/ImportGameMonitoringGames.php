<?php

namespace App\Console\Commands;

use App\Services\Discovery\GameMonitoringGameImport;
use Illuminate\Console\Command;

class ImportGameMonitoringGames extends Command
{
    protected $signature = 'gamemonitoring:games
        {--min-servers=1 : Skip games with fewer servers than this on their side}
        {--pages= : Stop after this many pages of their catalogue}
        {--activate : Create them switched on instead of awaiting review}
        {--dry-run : Read their catalogue and report what it would add}';

    protected $description = 'Add the games gamemonitoring.net lists and this catalog does not have';

    /**
     * The other half of reading a competitor: not which servers they have that
     * we do not, but which games.
     *
     * Run before `gamemonitoring:sync`, and run by hand — their catalogue is
     * 380 games and changes at the pace new games ship.
     *
     * What arrives is deliberately switched off. A row from this carries a
     * name, a slug and an appid, and none of the things a game page is made of
     * — artwork, a description, the port its servers actually use. `--activate`
     * exists for when that does not matter; leaving it out is the normal way.
     */
    public function handle(GameMonitoringGameImport $import): int
    {
        $write = ! $this->option('dry-run');
        $active = (bool) $this->option('activate');
        $minServers = max(0, (int) $this->option('min-servers'));
        $pages = $this->option('pages') === null ? null : max(1, (int) $this->option('pages'));

        if (! $write) {
            $this->warn('Dry run: nothing will be written.');
        }

        $report = $import->run($write, $minServers, $pages, $active);

        $this->line(sprintf(
            '  %8.0f ms  %4d listed  %4d already ours  %4d new  %4d skipped  %2d pages',
            $report->totalMs,
            $report->found,
            $report->existing,
            $report->created,
            $report->skipped,
            $report->pages,
        ));

        if (! $report->complete()) {
            $this->error("  stopped after {$report->pages} page(s): {$report->error}");
            $this->line('  what it read is written; run it again to read the rest.');
        }

        if ($report->created > 0 && $write) {
            $this->newLine();

            $this->info($active
                ? "{$report->created} game(s) added and live."
                : "{$report->created} game(s) added, switched off until somebody gives them artwork, a description and a real default port.");

            $this->line("  {$import->awaitingReview()} game(s) in the catalog are waiting to be switched on.");
            $this->line('  Their servers are read by gamemonitoring:sync, which only walks games that are on.');
        }

        return $report->complete() ? self::SUCCESS : self::FAILURE;
    }
}
