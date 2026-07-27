<?php

namespace App\Services\Catalog;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Turns votes and measured activity into the number a server is ranked by.
 *
 * The formula is deliberately boring and explainable — an owner who asks why
 * they dropped a place deserves an answer, and a score nobody can reason about
 * is a score people will assume is rigged.
 */
class ServerRanking
{
    /**
     * @param  int  $recentVotes  votes inside the configured window
     * @param  float  $averagePlayers  mean concurrent players over the last week
     * @param  float|null  $uptimePercent  null when never measured
     */
    public function points(
        int $recentVotes,
        float $averagePlayers,
        ?float $uptimePercent,
        bool $promoted,
    ): int {
        $points = $recentVotes * (int) config('ranking.vote_points');
        $points += $averagePlayers * (float) config('ranking.player_points');
        $points += ($uptimePercent ?? 0) / 100 * (float) config('ranking.uptime_points');

        if ($promoted) {
            $points += (int) config('ranking.promoted_points');
        }

        return (int) round($points);
    }

    /**
     * Recompute every active server in one pass.
     *
     * Aggregates are collected up front rather than per server: this runs on a
     * schedule over the whole catalog, and a query per server would turn a
     * cheap job into a slow one the moment discovery fills the table.
     *
     * @return int servers updated
     */
    public function recompute(): int
    {
        $since = now()->subDays((int) config('ranking.vote_window_days'))->toDateString();

        $recentVotes = DB::table('votes')
            ->selectRaw('server_id, count(*) as total')
            ->where('vote_day', '>=', $since)
            ->groupBy('server_id')
            ->pluck('total', 'server_id');

        $allVotes = DB::table('votes')
            ->selectRaw('server_id, count(*) as total')
            ->groupBy('server_id')
            ->pluck('total', 'server_id');

        $averagePlayers = DB::table('server_daily_stats')
            ->selectRaw('server_id, avg(players_avg) as average')
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->groupBy('server_id')
            ->pluck('average', 'server_id');

        $updated = 0;

        Server::query()
            ->active()
            ->select(['id', 'uptime_percent', 'promoted_until', 'rank_score', 'votes_count'])
            ->chunkById(500, function ($servers) use ($recentVotes, $allVotes, $averagePlayers, &$updated) {
                foreach ($servers as $server) {
                    $score = $this->points(
                        recentVotes: (int) ($recentVotes[$server->id] ?? 0),
                        averagePlayers: (float) ($averagePlayers[$server->id] ?? 0),
                        uptimePercent: $server->uptime_percent === null ? null : (float) $server->uptime_percent,
                        promoted: $server->isPromoted(),
                    );

                    $votes = (int) ($allVotes[$server->id] ?? 0);

                    if ($score === $server->rank_score && $votes === $server->votes_count) {
                        continue;
                    }

                    DB::table('servers')
                        ->where('id', $server->id)
                        ->update(['rank_score' => $score, 'votes_count' => $votes]);

                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Where a server sits in its game's table, and how far off the leader it is
     * — the pair the competitor shows as "#1 in the top, 4,216/4,216".
     *
     * @return array{position: int, total: int, points: int, leader_points: int}
     */
    public function standing(Server $server): array
    {
        $peers = Server::query()->active()->verified()->where('game_id', $server->game_id);

        return [
            'position' => (clone $peers)->where('rank_score', '>', $server->rank_score)->count() + 1,
            'total' => (clone $peers)->count(),
            'points' => $server->rank_score,
            'leader_points' => (int) (clone $peers)->max('rank_score'),
        ];
    }
}
