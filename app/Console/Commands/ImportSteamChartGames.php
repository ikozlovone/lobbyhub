<?php

namespace App\Console\Commands;

use App\Services\Discovery\SteamChartsImport;
use Illuminate\Console\Command;

class ImportSteamChartGames extends Command
{
    protected $signature = 'steamdb:games
        {--limit= : How far down the hundred to go}
        {--activate : Create them switched on instead of awaiting review}
        {--dry-run : Read the chart and report what it would add}';

    protected $description = "Add the games in SteamDB's top-100 chart that this catalog does not have";

    /**
     * A one-off, and worth saying why rather than scheduling it.
     *
     * gamemonitoring's list answers "which games have servers somebody
     * tracks". This one answers "which games are being played", and the games
     * in between are the interesting ones — a game with a live playerbase that
     * nobody is listing servers for is a gap worth filling. It is also full of
     * games that will never have a server to monitor: today's hundred includes
     * Dota 2, Blender and Bongo Cat.
     *
     * So what it produces is a shortlist for a person, not a catalog. Rows
     * arrive switched off and somebody decides, one at a time, which of them
     * this site should carry.
     */
    public function handle(SteamChartsImport $import): int
    {
        $write = ! $this->option('dry-run');
        $active = (bool) $this->option('activate');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        if (! $write) {
            $this->warn('Dry run: nothing will be written.');
        }

        $report = $import->run($write, $limit, $active);

        if (! $report->complete()) {
            $this->error("  {$report->error}");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %8.0f ms  %3d charted  %3d already ours  %3d new',
            $report->totalMs,
            $report->found,
            $report->existing,
            $report->created,
        ));

        if ($report->created > 0 && $write) {
            $this->newLine();
            $this->info($active
                ? "{$report->created} game(s) added and live."
                : "{$report->created} game(s) added, switched off.");
            $this->line('  The chart ranks by players, not by servers — go through them before switching any on.');
        }

        return self::SUCCESS;
    }
}
