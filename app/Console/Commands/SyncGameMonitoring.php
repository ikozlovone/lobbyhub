<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\Discovery\GameMonitoringSync;
use Illuminate\Console\Command;
use Throwable;

class SyncGameMonitoring extends Command
{
    protected $signature = 'gamemonitoring:sync
        {--game= : Slug of a single game; omit to walk every game with an app id}
        {--pages= : Stop after this many pages of their list per game}
        {--from= : Start at this offset in their list, to continue a walk that stopped}
        {--dry-run : Read the list and report what it would do, writing nothing}';

    protected $description = 'Mark the servers gamemonitoring.net also lists, and add the ones it has that we do not';

    /**
     * Run by hand, not on the scheduler.
     *
     * With no `--game` it walks every game the site is showing that has a
     * `steam_appid`, in the order it shows them — their filter is keyed on
     * Steam's appid, so a game without one (Minecraft, today) cannot be asked
     * for at all and is named as skipped rather than silently left out.
     *
     * It reads somebody else's API for as long as their catalog is, and what it
     * writes — a mark that only ever goes on once, and rows for addresses we
     * have never seen — is not something that needs doing every hour. Put it on
     * a schedule when there is a reason to, with a `--game` on it.
     *
     * The mark is half of a deletion decision, so `--dry-run` exists to be used
     * first: it walks the same list and reports the same numbers without
     * writing any of them.
     */
    public function handle(GameMonitoringSync $sync): int
    {
        /*
         * Switched-on games only, unless one is named.
         *
         * `gamemonitoring:games` can put three hundred rows in this table in a
         * minute, every one of them off and waiting for somebody to give it a
         * page. Walking those here would pull their server lists in behind
         * them — ARK alone is sixty-eight thousand — and fill the catalog with
         * servers for games the site does not show. Naming a game with
         * `--game` still reaches it either way: an explicit ask is an answer to
         * this question, not a case of it.
         */
        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->where('is_active', true)->whereNotNull('steam_appid')->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->error('No games to read. gamemonitoring is keyed by Steam appid, so a game needs steam_appid set and to be switched on.');

            return self::FAILURE;
        }

        $write = ! $this->option('dry-run');
        $pages = $this->option('pages') === null ? null : max(1, (int) $this->option('pages'));
        $from = max(0, (int) $this->option('from'));

        // An offset belongs to one game's list. Carried across all of them it
        // would start every game partway through its own, quietly skipping the
        // beginning of forty-four lists to continue one.
        if ($from > 0 && ! $this->option('game')) {
            $this->error('--from continues one game\'s list, so it needs --game with it.');

            return self::FAILURE;
        }

        if (! $write) {
            $this->warn('Dry run: nothing will be written.');
        }

        $totals = ['found' => 0, 'matched' => 0, 'marked' => 0, 'created' => 0, 'skipped' => 0];
        $failed = false;

        foreach ($games as $game) {
            if ($game->steam_appid === null) {
                $this->warn("  {$game->slug}: no steam_appid, skipped.");

                continue;
            }

            try {
                $report = $sync->run($game, $write, $pages, $from);
            } catch (Throwable $e) {
                // Nothing was read at all — a game with no app id, or the map
                // of the catalog failing to load. A list that dies partway
                // comes back as a report instead; see below.
                $this->error("  {$game->slug}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->line(sprintf(
                '  %-30s %8.0f ms  %6d found  %6d ours  %5d marked  %5d new  %5d skipped  %3d pages',
                $game->slug,
                $report->totalMs,
                $report->found,
                $report->matched,
                $report->marked,
                $report->created,
                $report->skipped,
                $report->pages,
            ));

            // A walk that stopped partway still marked and wrote whatever it
            // reached, and those writes are committed. So its numbers count
            // towards the totals and the reason is printed under them — the
            // run is repeatable, and re-running is how the rest gets read.
            if (! $report->complete()) {
                $this->error("    stopped after {$report->pages} page(s): {$report->error}");
                $this->line("    continue with --game={$game->slug} --from={$report->nextOffset}, or run it again from the top.");
                $failed = true;
            }

            $totals['found'] += $report->found;
            $totals['matched'] += $report->matched;
            $totals['marked'] += $report->marked;
            $totals['created'] += $report->created;
            $totals['skipped'] += $report->skipped;
        }

        $this->newLine();
        $this->info(sprintf(
            '%d listed, %d already ours, %d newly marked, %d added, %d skipped.',
            $totals['found'],
            $totals['matched'],
            $totals['marked'],
            $totals['created'],
            $totals['skipped'],
        ));

        // What the pass is actually for, said out loud: the servers with no
        // mark are the ones no competitor has, which is the set a cleanup
        // judges. Counting them here rather than leaving it to be worked out.
        if ($failed) {
            $this->warn('At least one list was not read to the end — the count below is not a verdict yet.');
        }

        if ($write) {
            $unmarked = Game::query()
                ->where('games.is_active', true)
                ->whereNotNull('steam_appid')
                ->join('servers', 'servers.game_id', '=', 'games.id')
                ->whereNull('servers.deleted_at')
                ->whereNull('servers.gamemonitoring_seen_at')
                ->count();

            $this->line("  {$unmarked} server(s) across those games carry no gamemonitoring mark.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
