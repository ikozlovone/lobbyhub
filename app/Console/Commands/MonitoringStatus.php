<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Monitoring\PollingSchedule;
use Illuminate\Console\Command;
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

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>schedule</>', '');
        $this->components->twoColumnDetail('  due now', (string) $due);
        $this->components->twoColumnDetail(
            '  oldest overdue by',
            $oldest ? now()->diffInSeconds($oldest, absolute: true).'s' : '—',
        );
        $this->components->twoColumnDetail('  batch size', (string) config('monitoring.batch_size'));

        // Actual versus intended cadence — the number that reveals a silent slowdown.
        $expected = 0.0;
        (clone $active)->select(['id', 'players_online', 'promoted_until'])
            ->chunkById(1000, function ($chunk) use (&$expected, $schedule) {
                foreach ($chunk as $server) {
                    $expected += $schedule->expectedHourlyQueries($server);
                }
            });

        $actual = ServerStat::where('recorded_at', '>=', now()->subHour())->count();

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>throughput (last hour)</>', '');
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

        $pending = DB::table('jobs')->where('queue', config('monitoring.queue'))->count();

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>queue</>', '');
        $this->components->twoColumnDetail('  pending jobs', (string) $pending);
    }
}
