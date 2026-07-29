<?php

namespace App\Services\Catalog;

use App\Enums\ServerStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The denormalized counts every catalog page reads.
 *
 * Games, modes, versions and countries each carry how many servers they hold
 * and how many players are on them. Catalog pages sort and filter on these, and
 * every facet chip shows one — computing them per request would mean aggregating
 * over the whole servers table on every page view.
 *
 * So they are recomputed rather than incremented. A running total drifts: a
 * server is soft-deleted, delisted, restored, moves between modes, goes offline
 * with a hundred players still counted against it. Four aggregate queries have
 * no such failure mode, and they are cheap enough to run on a schedule and
 * again whenever the catalog gains a member.
 */
class CatalogCounters
{
    /**
     * @return array<string, int> how many rows of each table hold servers
     */
    public function refresh(): array
    {
        $counts = [];

        DB::transaction(function () use (&$counts) {
            $counts['games'] = $this->refreshGames();
            $counts['game_modes'] = $this->refreshModes();
            $counts['game_versions'] = $this->refreshVersions();
            $counts['countries'] = $this->refreshCountries();
        });

        // The API serves these numbers from a short cache of its own. Leaving it
        // alone would mean the work above is invisible for another minute, which
        // is the whole complaint this method exists to answer.
        $this->flushApiCache();

        return $counts;
    }

    private function refreshGames(): int
    {
        $aggregates = $this->activeServers()
            ->selectRaw('game_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlineCount().' as online_servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('game_id')
            ->get();

        return $this->apply('games', $aggregates, ['servers_count', 'online_servers_count', 'players_online'], [
            'stats_synced_at' => now(),
        ]);
    }

    private function refreshModes(): int
    {
        $aggregates = $this->activeServers()
            ->join('game_mode_server as pivot', 'pivot.server_id', '=', 'servers.id')
            ->selectRaw('pivot.game_mode_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('pivot.game_mode_id')
            ->get();

        return $this->apply('game_modes', $aggregates, ['servers_count', 'players_online']);
    }

    private function refreshVersions(): int
    {
        $aggregates = $this->activeServers()
            ->whereNotNull('game_version_id')
            ->selectRaw('game_version_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('game_version_id')
            ->get();

        return $this->apply('game_versions', $aggregates, ['servers_count', 'players_online']);
    }

    private function refreshCountries(): int
    {
        $aggregates = $this->activeServers()
            ->whereNotNull('country_id')
            ->selectRaw('country_id as key')
            ->selectRaw('count(*) as servers_count')
            ->groupBy('country_id')
            ->get();

        return $this->apply('countries', $aggregates, ['servers_count']);
    }

    /** Soft-deleted and delisted servers must not show up in any counter. */
    private function activeServers(): Builder
    {
        return DB::table('servers')->whereNull('deleted_at')->where('is_active', true);
    }

    /** Portable conditional aggregate: sqlite has no FILTER in older builds. */
    private function onlineCount(): string
    {
        return "sum(case when status = '".ServerStatus::Online->value."' then 1 else 0 end)";
    }

    /** Offline servers report zero players, but be explicit rather than trusting that. */
    private function onlinePlayers(): string
    {
        return "sum(case when status = '".ServerStatus::Online->value."' then players_online else 0 end)";
    }

    /**
     * Zero every row, then write the aggregates back: a facet that lost its last
     * server has to fall to zero, and it simply will not appear in the group-by.
     *
     * @param  Collection<int, object>  $aggregates
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $extra
     */
    private function apply(string $table, $aggregates, array $columns, array $extra = []): int
    {
        DB::table($table)->update(array_fill_keys($columns, 0) + $extra);

        foreach ($aggregates as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = (int) ($row->{$column} ?? 0);
            }

            DB::table($table)->where('id', $row->key)->update($values + $extra);
        }

        return $aggregates->count();
    }

    /** Mirrors the keys GameController writes; see its CACHE_TTL. */
    private function flushApiCache(): void
    {
        Cache::forget('api:games');

        foreach (DB::table('games')->pluck('id') as $id) {
            Cache::forget("api:games:{$id}");
        }
    }
}
