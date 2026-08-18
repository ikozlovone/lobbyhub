<?php

namespace App\Services\Catalog;

use App\Enums\ServerStatus;
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
     * How many servers are read at a time, and how many are written at a time.
     *
     * The write batch is the smaller of the two because the statement below
     * compiles to one compound select per row, and SQLite — which the suite
     * runs on — refuses past five hundred of them. Same number, same reason, as
     * SteamCatalogSync.
     */
    private const READ_CHUNK = 500;

    private const WRITE_CHUNK = 200;

    /**
     * Recompute every active server in one pass.
     *
     * Aggregates are collected up front rather than per server: this runs on a
     * schedule over the whole catalog, and a query per server would turn a
     * cheap job into a slow one the moment discovery fills the table.
     *
     * The writes are batched for the same reason the reads are. They were not,
     * and the skip below hides less than it looks: `rank_score` is mostly a
     * function of average players, which moves, so almost every server is
     * genuinely different a quarter of an hour later — 314 of 322 in a local
     * run. At a few hundred thousand servers that is the same number of
     * single-row updates every fifteen minutes, which is a write rate on the
     * order of the whole monitor's, spent on bookkeeping.
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

        // `uptime_percent` lives on the state row now, so it's carried as
        // an aliased column off the join rather than off the server itself.
        Server::query()
            ->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->select([
                'servers.id',
                'servers.promoted_until',
                'servers.rank_score',
                'servers.votes_count',
                'server_states.uptime_percent',
            ])
            ->chunkById(self::READ_CHUNK, function ($servers) use ($recentVotes, $allVotes, $averagePlayers, &$updated) {
                $changed = [];

                foreach ($servers as $server) {
                    $score = $this->points(
                        recentVotes: (int) ($recentVotes[$server->id] ?? 0),
                        averagePlayers: (float) ($averagePlayers[$server->id] ?? 0),
                        uptimePercent: $server->uptime_percent === null ? null : (float) $server->uptime_percent,
                        promoted: $server->isPromoted(),
                    );

                    $votes = (int) ($allVotes[$server->id] ?? 0);

                    // Still worth asking. A row that would be written back
                    // unchanged is a dead tuple and an index update for nothing,
                    // and a catalog nobody has voted on all quarter is mostly
                    // these.
                    if ($score === $server->rank_score && $votes === $server->votes_count) {
                        continue;
                    }

                    $changed[] = ['id' => $server->id, 'rank_score' => $score, 'votes_count' => $votes];
                }

                $updated += $this->write($changed);
            }, column: 'servers.id', alias: 'id');

        return $updated;
    }

    /**
     * Write the scores this pass worked out, a batch at a time.
     *
     * Joining against a list of literals rather than an upsert, and `select …
     * union all select …` rather than a `values` list: the long version of both
     * reasons is on SteamCatalogSync::updateChunk, which does the same thing for
     * the same two engines. Short version — an upsert is checked against the row
     * it *proposes* to insert, so a payload of three columns aimed at an
     * existing id fails on the not-null columns it never meant to touch; and
     * only Postgres accepts a `values` list with column aliases, while the test
     * suite runs on SQLite.
     *
     * @param  list<array{id: int, rank_score: int, votes_count: int}>  $rows
     * @return int rows written
     */
    private function write(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        // Postgres cannot infer a bare placeholder's type in a select list and
        // says so; the casts go on the first branch, which is the one it reads
        // the shape from. SQLite has no such syntax at all.
        $casts = DB::connection()->getDriverName() === 'pgsql'
            ? ['::bigint', '::integer', '::integer']
            : ['', '', ''];

        foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
            $branches = [];
            $bindings = [];

            foreach ($chunk as $index => $row) {
                $branches[] = $index === 0
                    ? sprintf(
                        'select ?%s as "id", ?%s as "rank_score", ?%s as "votes_count"',
                        ...$casts,
                    )
                    : 'select ?, ?, ?';

                array_push($bindings, $row['id'], $row['rank_score'], $row['votes_count']);
            }

            DB::update(
                'update "servers" set "rank_score" = v."rank_score", "votes_count" = v."votes_count"'
                .' from ('.implode(' union all ', $branches).') as v'
                .' where "servers"."id" = v."id"',
                $bindings,
            );
        }

        return count($rows);
    }

    /**
     * Where a server sits in its game's table, and how far off the leader it is
     * — the pair the competitor shows as "#1 in the top, 4,216/4,216".
     *
     * @return array{position: int, total: int, points: int, leader_points: int}
     */
    public function standing(Server $server): array
    {
        // "Verified" = has a state row and is not `unknown` there. JOIN keeps
        // the peer set consistent with what the listings show.
        $peers = Server::query()
            ->active()
            ->join('server_states', function ($join) use ($server) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id')
                    ->where('server_states.game_id', $server->game_id);
            })
            ->where('server_states.status', '!=', ServerStatus::Unknown->value)
            ->where('servers.game_id', $server->game_id);

        return [
            'position' => (clone $peers)->where('servers.rank_score', '>', $server->rank_score)->count() + 1,
            'total' => (clone $peers)->count(),
            'points' => $server->rank_score,
            'leader_points' => (int) (clone $peers)->max('servers.rank_score'),
        ];
    }
}
