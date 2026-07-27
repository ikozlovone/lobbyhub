<?php

namespace App\Console\Commands;

use App\Models\ServerDailyStat;
use App\Models\ServerStat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RollupServerStats extends Command
{
    protected $signature = 'stats:rollup
        {--date= : Newest day to roll up, Y-m-d (default: today)}
        {--days=1 : How many days to (re)build, walking back from --date}
        {--prune-days=14 : Drop raw samples older than this; 0 disables pruning}
        {--uptime-window=30 : Days of daily stats behind servers.uptime_percent}';

    protected $description = 'Fold raw monitoring samples into daily stats, refresh uptime, prune old samples';

    /** Rows per upsert batch — keeps the statement well inside parameter limits. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $end = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $days = max(1, (int) $this->option('days'));

        for ($i = 0; $i < $days; $i++) {
            $day = $end->copy()->subDays($i);
            $rows = $this->rollupDay($day);
            $this->info("{$day->toDateString()}: {$rows} server(s) rolled up");
        }

        $this->refreshUptime((int) $this->option('uptime-window'));

        if ($pruneDays = (int) $this->option('prune-days')) {
            $cutoff = now()->subDays($pruneDays);
            $deleted = ServerStat::where('recorded_at', '<', $cutoff)->delete();
            $this->info("pruned {$deleted} raw sample(s) older than {$cutoff->toDateTimeString()}");
        }

        return self::SUCCESS;
    }

    /**
     * Aggregate one day of raw samples into server_daily_stats.
     * Portable SQL: no FILTER clause, so this also runs on sqlite in tests.
     */
    private function rollupDay(Carbon $day): int
    {
        $aggregates = ServerStat::query()
            ->selectRaw('server_id')
            ->selectRaw('avg(players_online) as players_avg')
            ->selectRaw('min(players_online) as players_min')
            ->selectRaw('max(players_online) as players_peak')
            ->selectRaw('max(players_max) as players_max')
            ->selectRaw('count(*) as samples_count')
            ->selectRaw('sum(case when is_online then 1 else 0 end) as online_samples_count')
            ->whereBetween('recorded_at', [$day, $day->copy()->endOfDay()])
            ->groupBy('server_id')
            ->get();

        $now = now();
        $total = 0;

        foreach ($aggregates->chunk(self::CHUNK) as $chunk) {
            $payload = $chunk->map(fn ($row) => [
                'server_id' => $row->server_id,
                'date' => $day->toDateString(),
                'players_avg' => round((float) $row->players_avg, 2),
                'players_min' => (int) $row->players_min,
                'players_peak' => (int) $row->players_peak,
                'players_max' => (int) $row->players_max,
                'samples_count' => (int) $row->samples_count,
                'online_samples_count' => (int) $row->online_samples_count,
                'uptime_percent' => $row->samples_count > 0
                    ? round($row->online_samples_count * 100 / $row->samples_count, 2)
                    : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            ServerDailyStat::upsert($payload, ['server_id', 'date'], [
                'players_avg', 'players_min', 'players_peak', 'players_max',
                'samples_count', 'online_samples_count', 'uptime_percent', 'updated_at',
            ]);

            $total += count($payload);
        }

        return $total;
    }

    /**
     * servers.uptime_percent is a rolling window over the daily rollups,
     * weighted by sample count so partially-monitored days don't skew it.
     */
    private function refreshUptime(int $windowDays): void
    {
        $since = now()->subDays(max(1, $windowDays))->startOfDay()->toDateString();

        ServerDailyStat::query()
            ->selectRaw('server_id')
            ->selectRaw('sum(online_samples_count) as online_samples')
            ->selectRaw('sum(samples_count) as samples')
            ->where('date', '>=', $since)
            ->groupBy('server_id')
            ->havingRaw('sum(samples_count) > 0')
            ->orderBy('server_id')
            ->chunk(self::CHUNK, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('servers')
                        ->where('id', $row->server_id)
                        ->update([
                            'uptime_percent' => round($row->online_samples * 100 / $row->samples, 2),
                        ]);
                }
            });

        $this->info("uptime refreshed over a {$windowDays}-day window");
    }
}
