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
    public function __construct(
        private readonly FrontendCache $frontend,
        private readonly ListingCache $listings,
    ) {}

    /**
     * @return array<string, int> how many rows of each table hold servers
     */
    public function refresh(): array
    {
        $counts = [];
        $gained = [];

        DB::transaction(function () use (&$counts, &$gained) {
            [$counts['games'], $gained] = $this->refreshGames();
            $counts['game_modes'] = $this->refreshModes();
            $counts['game_versions'] = $this->refreshVersions();
            $counts['countries'] = $this->refreshCountries();
        });

        // The API serves these numbers from a short cache of its own. Leaving it
        // alone would mean the work above is invisible for another minute, which
        // is the whole complaint this method exists to answer.
        $this->flushApiCache();

        /*
         * The listings of the games that changed shape.
         *
         * `$gained` is games whose server count moved, which is exactly the
         * change a window cannot cover politely: somebody filled in the add
         * form and came back to find their server, and a minute of being told
         * it is not there reads as the form having failed. Everything else a
         * listing shows — players, status, votes, rank — moves on its own
         * schedule and is left to the window; purging over those would mean
         * nothing was ever cached at all, which is the same argument republish()
         * makes about the navigation rail below.
         */
        $this->listings->forget($gained);

        $this->republish($gained);

        return $counts;
    }

    /**
     * @return array{0: int, 1: list<string>} rows written, and the slugs of the
     *                                        games whose listing changed shape
     */
    private function refreshGames(): array
    {
        $before = DB::table('games')->pluck('servers_count', 'slug');

        $aggregates = $this->activeServers()
            ->selectRaw('game_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlineCount().' as online_servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('game_id')
            ->get();

        $rows = $this->apply('games', $aggregates, ['servers_count', 'online_servers_count', 'players_online'], [
            'stats_synced_at' => now(),
        ]);

        $after = DB::table('games')->pluck('servers_count', 'slug');

        return [$rows, $after
            ->reject(fn (int $count, string $slug) => $count === ($before[$slug] ?? null))
            ->keys()
            ->all()];
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

    /**
     * Tell the frontend that a game has gained or lost servers.
     *
     * Listings themselves need no telling: the frontend reads them when a
     * request arrives, so a server the monitor listed a second ago is on the
     * page a second later. What it does keep is the catalog behind its
     * navigation rail — where a game appears only once it has a server — and
     * that is what this expires.
     *
     * Membership only, which is what `$slugs` already holds: player counts and
     * online tallies move every minute on their own, and purging the rail over
     * those would mean it is never cached at all.
     *
     * @param  list<string>  $slugs  games whose server count changed
     */
    private function republish(array $slugs): void
    {
        if ($slugs === []) {
            return;
        }

        $this->frontend->invalidate('games');
    }

    /** Soft-deleted and delisted servers must not show up in any counter. */
    /**
     * The servers these counts are about: the ones a visitor can actually open.
     *
     * `status = unknown` is excluded to match ServerListing, which shows only
     * servers our own monitor has reached — discovery imports candidates from
     * Steam's index by the thousand, and counting them here promised a catalog
     * of 1,236 next to a table of 640. The gap closed itself as the queue caught
     * up, which is the worst kind of wrong: right eventually, and never while
     * anyone was looking.
     */
    private function activeServers(): Builder
    {
        return DB::table('servers')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('status', '!=', ServerStatus::Unknown->value);
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
