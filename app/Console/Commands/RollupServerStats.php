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
     *
     * One statement, because there is nothing here PHP was needed for: the
     * figure is an aggregate of two columns and a division. It used to read the
     * aggregate and then write it back a row at a time — 314 updates for 322
     * servers, and linear in the catalog, so every hour at a few hundred
     * thousand servers meant a few hundred thousand single-row updates against
     * the database the monitor is already busy with.
     *
     * Servers with no samples in the window are left alone rather than zeroed,
     * which is what the `having` did before and still does: no measurements is
     * not the same fact as no uptime, and a server that has been quiet for a
     * month should keep the last figure anyone measured.
     */
    private function refreshUptime(int $windowDays): void
    {
        $since = now()->subDays(max(1, $windowDays))->startOfDay()->toDateString();

        $updated = DB::update(<<<'SQL'
            update "servers"
            set "uptime_percent" = round(v."online_samples" * 100.0 / v."samples", 2)
            from (
                select "server_id",
                       sum("online_samples_count") as "online_samples",
                       sum("samples_count") as "samples"
                from "server_daily_stats"
                where "date" >= ?
                group by "server_id"
                having sum("samples_count") > 0
            ) as v
            where "servers"."id" = v."server_id"
        SQL, [$since]);

        $this->info("uptime refreshed for {$updated} server(s) over a {$windowDays}-day window");
    }
}
