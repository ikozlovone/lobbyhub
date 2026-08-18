<?php

namespace App\Services\Admin;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Monitoring\PollingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the monitor is doing, in numbers.
 *
 * The same questions `monitoring:status` answers on the command line, plus the
 * ones only a screen has room for: where the catalog stands by status, how long
 * servers take to answer, and which games are dragging.
 *
 * All of it is read straight from the tables rather than from the denormalized
 * counters the public pages use — an admin looking at these numbers is usually
 * asking whether those counters are right.
 */
class MonitoringReport
{
    public function __construct(private PollingSchedule $schedule) {}

    /**
     * How the catalog divides by status.
     *
     * `unknown` is the interesting one: those are rows discovery imported and
     * the monitor has not reached yet, and they are invisible on the site until
     * it does. A number that stays put means the queue is not draining.
     */
    public function statuses(): array
    {
        // Counts group by the state's status; both is_active (servers) and
        // status (states) are needed, so JOIN.
        $counts = DB::table('servers')
            ->where('servers.is_active', true)
            ->whereNull('servers.deleted_at')
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->select('server_states.status', DB::raw('count(*) as total'))
            ->groupBy('server_states.status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();

        return [
            'total' => $total,
            'online' => (int) ($counts[ServerStatus::Online->value] ?? 0),
            'offline' => (int) ($counts[ServerStatus::Offline->value] ?? 0),
            'unknown' => (int) ($counts[ServerStatus::Unknown->value] ?? 0),
            'never_queried' => (int) DB::table('servers')
                ->where('servers.is_active', true)
                ->whereNull('servers.deleted_at')
                ->join('server_states', function ($join) {
                    $join->on('server_states.server_id', '=', 'servers.id')
                        ->on('server_states.game_id', '=', 'servers.game_id');
                })
                ->whereNull('server_states.last_queried_at')
                ->count(),
            'inactive' => (int) Server::query()->where('is_active', false)->count(),
        ];
    }

    /**
     * Whether the monitor is keeping its own appointments.
     *
     * `expected` is what the tiers in config/monitoring.php promise for the
     * catalog as it stands; `actual` is how many samples the last hour really
     * produced. The two drifting apart is the first sign of a worker that has
     * stopped or a batch size set too low for the number of servers.
     */
    public function throughput(): array
    {
        $activeWithState = fn () => Server::query()->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            });

        $expected = $this->schedule->expectedHourlyQueriesForActive();

        $since = now()->subHour();
        $actual = ServerStat::where('recorded_at', '>=', $since)->count();

        // The window is only as long as there is data for. Comparing an hour of
        // expectation against a monitor started two minutes ago reports 4% and
        // reads as a failure — which is exactly when someone opens this page.
        $firstSample = ServerStat::where('recorded_at', '>=', $since)->min('recorded_at');
        $minutes = $firstSample ? max(1, now()->diffInMinutes($firstSample, absolute: true)) : 60;
        $scaled = $expected * min(1, $minutes / 60);

        return [
            'expected_hourly' => (int) round($expected),
            'actual_last_hour' => $actual,
            'window_minutes' => (int) $minutes,
            'ratio' => $scaled > 0 ? round($actual / $scaled * 100) : null,
            'due_now' => (int) $activeWithState()->where('server_states.next_query_at', '<=', now())->count(),
            'oldest_due_at' => $activeWithState()->where('server_states.next_query_at', '<=', now())->min('server_states.next_query_at'),
            'batch_size' => (int) config('monitoring.batch_size'),
        ];
    }

    /**
     * How long a check takes, and how often one fails.
     *
     * Latency is the server's own response time, not ours: the socket is open
     * for as long as the machine on the other end takes to answer. It is the
     * number that tells a slow host from a busy one.
     */
    public function timings(): array
    {
        $since = now()->subDay();

        $row = ServerStat::where('recorded_at', '>=', $since)
            ->selectRaw('count(*) as samples')
            ->selectRaw('avg(latency_ms) as avg_latency')
            ->selectRaw('max(latency_ms) as max_latency')
            ->selectRaw("sum(case when is_online then 0 else 1 end) as failures")
            ->first();

        $samples = (int) ($row->samples ?? 0);
        $failures = (int) ($row->failures ?? 0);

        return [
            'samples_24h' => $samples,
            'avg_latency_ms' => $row?->avg_latency !== null ? (int) round((float) $row->avg_latency) : null,
            'max_latency_ms' => $row?->max_latency !== null ? (int) $row->max_latency : null,
            'failures_24h' => $failures,
            'failure_rate' => $samples > 0 ? round($failures / $samples * 100, 1) : null,
            // Not the configured interval but the one that happened: samples in
            // a day divided across the servers that produced them.
            'checks_per_server_24h' => $samples > 0
                ? round($samples / max(1, (int) DB::table('servers')
                    ->where('servers.is_active', true)
                    ->whereNull('servers.deleted_at')
                    ->join('server_states', function ($join) {
                        $join->on('server_states.server_id', '=', 'servers.id')
                            ->on('server_states.game_id', '=', 'servers.game_id');
                    })
                    ->whereNotNull('server_states.last_queried_at')
                    ->count()), 1)
                : null,
        ];
    }

    /**
     * The same split per game, because trouble is rarely spread evenly — one
     * game's protocol driver failing looks exactly like a healthy catalog until
     * the rows are separated.
     */
    public function games(): Collection
    {
        return Game::query()
            // Qualified: servers carries a column of the same name, and this
            // query joins the two.
            ->where('games.is_active', true)
            ->leftJoin('servers', function ($join) {
                $join->on('servers.game_id', '=', 'games.id')
                    ->whereNull('servers.deleted_at')
                    ->where('servers.is_active', true);
            })
            ->leftJoin('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->groupBy('games.id', 'games.name', 'games.slug', 'games.query_protocol')
            ->select('games.name', 'games.slug', 'games.query_protocol')
            ->selectRaw('count(servers.id) as total')
            ->selectRaw('sum(case when server_states.status = ? then 1 else 0 end) as online', [ServerStatus::Online->value])
            ->selectRaw('sum(case when server_states.status = ? then 1 else 0 end) as offline', [ServerStatus::Offline->value])
            ->selectRaw('sum(case when server_states.status = ? then 1 else 0 end) as unknown', [ServerStatus::Unknown->value])
            ->selectRaw('sum(case when server_states.next_query_at <= ? then 1 else 0 end) as due', [now()])
            ->orderByDesc('total')
            ->get();
    }

    /**
     * The slowest servers to answer, averaged over the day.
     *
     * From the samples rather than from `servers`, which keeps no latency of
     * its own — and an average over a day is the honest number anyway: a single
     * check catches whatever the host was doing that second.
     */
    public function slowest(int $limit = 10): Collection
    {
        return ServerStat::query()
            ->where('server_stats.recorded_at', '>=', now()->subDay())
            ->where('server_stats.is_online', true)
            ->join('servers', 'servers.id', '=', 'server_stats.server_id')
            ->join('games', 'games.id', '=', 'servers.game_id')
            ->whereNull('servers.deleted_at')
            ->where('servers.is_active', true)
            ->groupBy('servers.id', 'servers.slug', 'servers.name', 'games.name')
            ->select('servers.slug', 'servers.name', 'games.name as game')
            ->selectRaw('avg(server_stats.latency_ms) as avg_latency')
            ->selectRaw('count(*) as samples')
            ->havingRaw('avg(server_stats.latency_ms) is not null')
            ->orderByDesc('avg_latency')
            ->limit($limit)
            ->get();
    }

    /** Servers the monitor has failed to reach for the longest. */
    public function longestOffline(int $limit = 10): Collection
    {
        return Server::query()->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->where('server_states.status', ServerStatus::Offline->value)
            ->with('game:id,name,slug')
            ->orderBy('server_states.last_online_at')
            ->limit($limit)
            ->get([
                'servers.id',
                'servers.game_id',
                'servers.slug',
                'servers.name',
                'server_states.last_online_at',
                'server_states.last_queried_at',
                'server_states.failed_queries_count',
            ]);
    }
}
