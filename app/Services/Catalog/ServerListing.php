<?php

namespace App\Services\Catalog;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

class ServerListing
{
    /**
     * Only these are accepted.
     *
     * `players` and `rank` are the two that carry the traffic and the two that
     * have a partial index behind them; the rest still sort the game out of the
     * heap. That is deliberate — see the migration — and the moment one of them
     * shows up in the slow log it wants an index of its own, not a rewrite.
     */
    private const SORTS = [
        'players' => ['players_online', 'desc'],
        'rank' => ['rank_score', 'desc'],
        'votes' => ['votes_count', 'desc'],
        'uptime' => ['uptime_percent', 'desc'],
        'wiped' => ['wiped_at', 'desc'],
        'name' => ['name', 'asc'],
        // Insertion order, by primary key rather than created_at: the same
        // ordering, on an index that already exists.
        'newest' => ['id', 'desc'],
    ];

    /**
     * The buckets the listing chips filter by, in the order they are offered.
     *
     * Not the same thing as the `status` column, which is why they live here
     * rather than in the enum: "empty" and "full" are questions about players
     * against capacity, and both are only meaningful of a server we can see.
     */
    private const STATUSES = [
        'online' => 'Online',
        'players' => 'Has players',
        'full' => 'Full',
        'empty' => 'Empty',
        'offline' => 'Offline',
    ];

    /**
     * Maps are free text reported by the server, and a busy game invents new
     * ones daily. The chip offers the ones worth browsing, not all of them.
     */
    private const MAX_MAP_FACETS = 40;

    /**
     * How many promoted servers a listing will lift to the front.
     *
     * Placements sell in ones and twos, so this is a ceiling nobody is near
     * rather than a policy. Past it, a promoted server simply keeps its own
     * place in the listing instead of being pinned: worth less than it paid
     * for, but the alternative — an unbounded head — is a page whose length
     * depends on how much was sold that week.
     */
    private const MAX_PROMOTED = 50;

    /**
     * The listing, as pages.
     *
     * Two queries rather than one, because promotion cannot be part of the
     * order. "Above everything else" is `promoted_until > now()`, an expression
     * over a clock, and an expression cannot be indexed; as the leading sort
     * key it forced the whole game out of the heap and through a sort for every
     * twenty-five rows shown — 368 ms and 283 MB of reads on Rust. See
     * 2026_08_09_120000_add_listing_indexes_to_servers.
     *
     * So the pinned servers are fetched on their own — a handful of rows off a
     * tiny index — and the listing beneath them is ordered by columns an index
     * can hold. The two are then treated as one long list and cut into pages
     * here, which keeps `total` honest and the offsets of page two onwards
     * where they were.
     */
    public function paginate(?Game $game, array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 24), 1), 100);
        $page = Paginator::resolveCurrentPage();

        $pinned = $this->pinned($game, $filters);
        $rest = $this->query($game, $filters);

        // Pinned rows are removed from the listing by id, not by repeating the
        // promoted condition: past MAX_PROMOTED the rest are not pinned, and
        // filtering them out by condition would drop them from the site.
        if ($pinned->isNotEmpty()) {
            $rest->whereIntegerNotInRaw('id', $pinned->modelKeys());
        }

        $total = $pinned->count() + $rest->toBase()->getCountForPagination();

        $offset = ($page - 1) * $perPage;

        $head = $pinned->slice($offset, $perPage)->values();
        $tail = $head->count() < $perPage
            ? $rest->offset(max(0, $offset - $pinned->count()))
                ->limit($perPage - $head->count())
                ->get()
            : new Collection;

        return (new Paginator($head->concat($tail), $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->appends($filters);
    }

    /**
     * The promoted servers this listing puts at the front.
     *
     * The same filters as the listing, so a promoted server that does not match
     * the chips is not smuggled past them, and the same sort, so two of them
     * come out in a sensible order relative to each other.
     */
    private function pinned(?Game $game, array $filters): Collection
    {
        return $this->query($game, $filters)
            ->promoted()
            ->limit(self::MAX_PROMOTED)
            ->get();
    }

    /**
     * The listing, filtered and ordered, without the promoted head.
     *
     * A null $game widens the same listing to the whole catalog. The home page
     * needs "the busiest servers we know of", not "the busiest servers in one
     * game" — and building that from one request per game would be 46 round
     * trips today and worse with every game added. Everything else about the
     * query is identical, which is why this is a nullable argument rather than
     * a second listing that would drift out of step with this one.
     *
     * Promotion is not applied here; paginate() adds it. Ordering by it is what
     * made this query read the whole game, and the same builder is what fetches
     * the promoted rows themselves.
     */
    public function query(?Game $game, array $filters): Builder
    {
        $query = Server::query()
            ->active()
            ->verified()
            ->with(['country:id,code,name,slug', 'version:id,slug,name']);

        if ($game !== null) {
            $query->where('game_id', $game->id);
        } else {
            // Cross-game rows are useless without saying which game they are
            // from, and the frontend needs the protocol to know whether a
            // "Connect" button can be a steam:// link.
            $query->with('game:id,slug,name,query_protocol');
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'players'] ?? self::SORTS['players'];
        $query->orderBy($column, $direction);

        // A rank of zero is the normal state for a server nobody has voted for
        // and that we have not watched for a full week yet. Without a tiebreak,
        // the ranked listing of a young catalog is ordered by insertion — which
        // is to say, not ordered at all.
        if ($column === 'rank_score') {
            $query->orderBy('players_online', 'desc');
        }

        $query->orderBy('id');

        return $this->applyFilters($query, $game, $filters);
    }

    private function applyFilters(Builder $query, ?Game $game, array $filters): Builder
    {
        // Catalog-wide only: inside a game the route already decided which one.
        if ($game === null && $slug = $filters['game'] ?? null) {
            $query->whereHas('game', fn (Builder $q) => $q->where('games.slug', $slug));
        }

        /*
         * "Recently wiped" is a question only some games can answer, and the
         * honest way to ask it is of the data rather than of a hard-coded list
         * of games: a server has a wipe date or it does not. Servers whose date
         * is in the distant past are not news, so the window is part of the
         * filter — without it this degrades into "every Rust server, ever".
         */
        if (($filters['wiped'] ?? null) !== null) {
            $days = max(1, min((int) $filters['wiped'], 90));

            $query->whereNotNull('wiped_at')
                ->where('wiped_at', '>=', now()->subDays($days))
                ->where('wiped_at', '<=', now());
        }

        if ($mode = $filters['mode'] ?? null) {
            $query->whereHas('modes', fn (Builder $q) => $q->where('game_modes.slug', $mode));
        }

        if ($version = $filters['version'] ?? null) {
            $query->whereHas('version', fn (Builder $q) => $q->where('game_versions.slug', $version));
        }

        if ($country = $filters['country'] ?? null) {
            $query->whereHas('country', fn (Builder $q) => $q->where('countries.slug', $country));
        }

        if ($status = $filters['status'] ?? null) {
            $this->applyStatus($query, $status);
        }

        // The literal string the server reported, because that is what the map
        // facet lists — these names have no canonical form to slug towards.
        if ($map = $filters['map'] ?? null) {
            $query->where('map', $map);
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            // Folded on both sides rather than left to LIKE: Postgres compares
            // it case-sensitively, so a search for "atlas" would walk straight
            // past every server that spells itself Atlas.
            $needle = '%'.mb_strtolower($search).'%';

            $query->where(function (Builder $q) use ($needle) {
                $q->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(host) like ?', [$needle]);
            });
        }

        return $query;
    }

    /**
     * Empty and full are asked only of servers we can currently see: an offline
     * server holds no players by definition and would swamp the empty bucket
     * with machines nobody can join.
     */
    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'online' => $query->online(),
            'offline' => $query->where($query->qualifyColumn('status'), ServerStatus::Offline),
            'empty' => $query->online()->where('players_online', 0),
            'players' => $query->online()->where('players_online', '>', 0),
            // Servers that report no capacity would otherwise all count as full.
            'full' => $query->online()
                ->where('players_max', '>', 0)
                ->whereColumn('players_online', '>=', 'players_max'),
            default => null,
        };
    }

    /**
     * Facet counts for a game's landing page.
     *
     * Modes and versions carry their own counters because they belong to one
     * game. Countries do not: `countries.servers_count` is global across every
     * game, so a per-game breakdown has to be aggregated here.
     */
    public function facets(Game $game): array
    {
        return [
            'statuses' => $this->statusFacets($game),
            'modes' => $this->facetRows($game->modes()->active()),
            'versions' => $this->facetRows($game->versions()->active()),
            'countries' => $this->countryFacets($game),
            'maps' => $this->mapFacets($game),
        ];
    }

    /**
     * How many servers sit in each status chip.
     *
     * Counted across the whole game rather than through whatever filters are
     * currently applied: a number that shrinks as you narrow tells you what is
     * left, and these chips are there to say what the game looks like.
     *
     * One aggregate rather than five round trips, written as case-sums rather
     * than FILTER so the same SQL runs under sqlite in the test suite.
     *
     * @return array<int, array{slug: string, name: string, servers_count: int}>
     */
    private function statusFacets(Game $game): array
    {
        $online = ServerStatus::Online->value;

        $row = Server::query()
            ->active()
            ->verified()
            ->where('game_id', $game->id)
            ->selectRaw(<<<'SQL'
                sum(case when status = ? then 1 else 0 end) as online_count,
                sum(case when status = ? then 1 else 0 end) as offline_count,
                sum(case when status = ? and players_online = 0 then 1 else 0 end) as empty_count,
                sum(case when status = ? and players_online > 0 then 1 else 0 end) as players_count,
                sum(case when status = ? and players_max > 0 and players_online >= players_max
                    then 1 else 0 end) as full_count
            SQL, [$online, ServerStatus::Offline->value, $online, $online, $online])
            ->first();

        return collect(self::STATUSES)
            ->map(fn (string $name, string $slug) => [
                'slug' => $slug,
                'name' => $name,
                'servers_count' => (int) ($row?->{"{$slug}_count"} ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The maps worth offering as a filter.
     *
     * `slug` carries the map name verbatim because that is also the filter
     * value — see applyFilters. Slugging it would need a reverse lookup for
     * every query against a column that is only ever free text.
     *
     * @return array<int, array{slug: string, name: string, servers_count: int}>
     */
    private function mapFacets(Game $game): array
    {
        return Server::query()
            ->active()
            ->verified()
            ->where('game_id', $game->id)
            ->whereNotNull('map')
            ->where('map', '!=', '')
            ->groupBy('map')
            ->orderByRaw('count(*) desc')
            ->orderBy('map')
            ->limit(self::MAX_MAP_FACETS)
            ->get(['map', DB::raw('count(*) as servers_count')])
            ->map(fn ($row) => [
                'slug' => $row->map,
                'name' => $row->map,
                'servers_count' => (int) $row->servers_count,
            ])
            ->all();
    }

    /**
     * Plain arrays, deliberately: these go straight into the response cache, and
     * caching Eloquent objects means storing model state and unserializing it
     * back into live models on every hit.
     */
    private function facetRows(Builder|Relation $query): array
    {
        return $query
            ->where('servers_count', '>', 0)
            ->orderByDesc('servers_count')
            ->get(['slug', 'name', 'servers_count', 'players_online'])
            ->map(fn ($row) => [
                'slug' => $row->slug,
                'name' => $row->name,
                'servers_count' => $row->servers_count,
                'players_online' => $row->players_online,
            ])
            ->all();
    }

    /** @return array<int, array{code: string, name: string, slug: string, servers_count: int}> */
    private function countryFacets(Game $game): array
    {
        return Server::query()
            ->active()
            ->where('game_id', $game->id)
            ->whereNotNull('country_id')
            ->join('countries', 'countries.id', '=', 'servers.country_id')
            ->groupBy('countries.code', 'countries.name', 'countries.slug')
            ->orderByRaw('count(*) desc')
            ->get([
                'countries.code',
                'countries.name',
                'countries.slug',
                DB::raw('count(*) as servers_count'),
            ])
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'slug' => $row->slug,
                'servers_count' => (int) $row->servers_count,
            ])
            ->all();
    }

    /** @return list<string> */
    public static function sorts(): array
    {
        return array_keys(self::SORTS);
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_keys(self::STATUSES);
    }
}
