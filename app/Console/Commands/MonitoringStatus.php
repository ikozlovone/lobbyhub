<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Monitoring\PollingSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonitoringStatus extends Command
{
    protected $signature = 'monitoring:status';

    protected $description = 'Show whether monitoring is keeping up with its own schedule';

    public function handle(PollingSchedule $schedule): int
    {
        $active = Server::query()->active();

        $due = (clone $active)->where('next_query_at', '<=', now())->count();
        $oldest = (clone $active)->where('next_query_at', '<=', now())->min('next_query_at');
        $neverQueried = (clone $active)->whereNull('last_queried_at')->count();

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>servers</>', '');
        $this->components->twoColumnDetail('  active', (string) (clone $active)->count());
        $this->components->twoColumnDetail('  online', (string) (clone $active)->where('status', ServerStatus::Online)->count());
        $this->components->twoColumnDetail('  offline', (string) (clone $active)->where('status', ServerStatus::Offline)->count());
        $this->components->twoColumnDetail('  never queried', (string) $neverQueried);

        /*
         * Which half of the monitor each server belongs to.
         *
         * The interesting figure is the second one. Everything Steam is
         * currently listing costs no packet at all, so the queue's real work is
         * whatever is left — and if that number climbs while the sweep is
         * supposedly running, the sweep is the thing to look at, not the
         * workers.
         */
        // The same two windows the dispatcher applies, or this would report a
        // coverage the poller does not agree with.
        $populated = now()->subSeconds((int) config('monitoring.steam_trust_populated'));
        $quiet = now()->subSeconds((int) config('monitoring.steam_trust_quiet'));

        $covered = (clone $active)
            ->whereNotNull('steam_seen_at')
            ->where(fn ($query) => $query
                ->where(fn ($busy) => $busy->where('players_online', '>', 0)->where('steam_seen_at', '>=', $populated))
                ->orWhere(fn ($empty) => $empty->where('players_online', '=', 0)->where('steam_seen_at', '>=', $quiet)))
            ->count();

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>source</>', '<fg=gray>steam sweep</>');
        $this->components->twoColumnDetail('  covered by the sweep', (string) $covered);
        $this->components->twoColumnDetail('  left to the poller', (string) ((clone $active)->count() - $covered));
        $this->components->twoColumnDetail(
            '  last seen in a list',
            ($seen = (clone $active)->max('steam_seen_at'))
                ? now()->diffInSeconds(Carbon::parse($seen), absolute: true).'s ago'
                : '—',
        );

        /*
         * What the catalog is actually showing, as opposed to what it is owed.
         *
         * "oldest overdue by" is measured against `next_query_at`, and the
         * dispatcher moves that when it *queues* a server, not when the server
         * is reached — so a query sitting in the queue for hours reads here as
         * dealt with. During a backlog the two numbers come apart, and this is
         * the one that matches what a visitor sees.
         *
         * Never-queried servers are excluded rather than counted as infinitely
         * stale: they are not in any listing yet, and they have their own line
         * above.
         */
        $stalest = (clone $active)->whereNotNull('last_queried_at')->min('last_queried_at');

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>schedule</>', '');
        $this->components->twoColumnDetail('  due now', (string) $due);
        $this->components->twoColumnDetail(
            '  oldest overdue by',
            $oldest ? now()->diffInSeconds($oldest, absolute: true).'s' : '—',
        );
        $this->components->twoColumnDetail(
            '  stalest reading',
            $stalest ? now()->diffInSeconds(Carbon::parse($stalest), absolute: true).'s' : '—',
        );
        $this->components->twoColumnDetail('  batch size', (string) config('monitoring.batch_size'));

        // Actual versus intended cadence — the number that reveals a silent slowdown.
        $expected = $schedule->expectedHourlyQueriesForActive();

        $since = now()->subHour();
        $actual = ServerStat::where('recorded_at', '>=', $since)->count();

        /*
         * Scale the expectation to the window we actually have data for.
         *
         * Comparing an hour's worth of expected queries against a monitor that
         * started two minutes ago reports 4% and looks like a failure — which is
         * exactly when someone runs this command.
         */
        $firstSample = ServerStat::where('recorded_at', '>=', $since)->min('recorded_at');
        $covered = $firstSample
            ? max(60, now()->diffInSeconds(Carbon::parse($firstSample), absolute: true))
            : 3600;
        $expected *= min($covered, 3600) / 3600;

        $this->line('');
        $this->components->twoColumnDetail(
            '<fg=gray>throughput</>',
            '<fg=gray>'.($covered < 3500 ? 'last '.round($covered / 60).' min' : 'last hour').'</>',
        );
        $this->components->twoColumnDetail('  queries expected', (string) round($expected));
        $this->components->twoColumnDetail('  queries actual', (string) $actual);

        if ($expected > 0) {
            $ratio = round($actual / $expected * 100);
            $colour = $ratio >= 90 ? 'green' : ($ratio >= 60 ? 'yellow' : 'red');
            $this->components->twoColumnDetail('  keeping up', "<fg={$colour}>{$ratio}%</>");
        }

        $this->reportQueueDepth();

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>cadence tiers</>', '');
        foreach (config('monitoring.tiers', []) as $tier) {
            $this->components->twoColumnDetail(
                "  {$tier['min_players']}+ players",
                'every '.$tier['interval'].'s',
            );
        }
        $this->components->twoColumnDetail('  promoted', 'every '.config('monitoring.promoted_interval').'s');
        $this->line('');

        return self::SUCCESS;
    }

    /** Only the database queue driver keeps its backlog somewhere we can read. */
    private function reportQueueDepth(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        $queries = DB::table('jobs')->where('queue', config('monitoring.queue'))->count();
        $sweeps = DB::table('jobs')->where('queue', config('monitoring.steam_queue'))->count();
        $ceiling = (int) config('monitoring.max_queue_depth');

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>queue</>', '');
        $this->components->twoColumnDetail(
            '  per-server queries',
            $ceiling > 0 && $queries >= $ceiling
                // The dispatcher has stopped adding, which is a thing to know
                // before concluding that nothing is due.
                ? "<fg=yellow>{$queries} — at the ceiling, dispatcher paused</>"
                : (string) $queries,
        );
        // Its own line because it has its own queue, and because a number that
        // sits still here is the sweep not running at all.
        $this->components->twoColumnDetail('  steam sweeps', (string) $sweeps);
    }
}
