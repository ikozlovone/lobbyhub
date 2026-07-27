<?php

namespace App\Services\Catalog;

use App\Models\Game;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class ServerListing
{
    /** Only these are accepted, and each maps onto an existing index. */
    private const SORTS = [
        'players' => ['players_online', 'desc'],
        'rank' => ['rank_score', 'desc'],
        'votes' => ['votes_count', 'desc'],
        'uptime' => ['uptime_percent', 'desc'],
        'wiped' => ['wiped_at', 'desc'],
        'name' => ['name', 'asc'],
    ];

    public function paginate(Game $game, array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 24), 1), 100);

        return $this->query($game, $filters)
            ->paginate($perPage)
            ->appends($filters);
    }

    public function query(Game $game, array $filters): Builder
    {
        $query = Server::query()
            ->active()
            ->where('game_id', $game->id)
            ->with(['country:id,code,name,slug', 'version:id,slug,name']);

        // Promoted servers ride above the sort — that is what the placement buys.
        $query->orderByRaw('case when promoted_until > ? then 0 else 1 end', [now()]);

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'players'] ?? self::SORTS['players'];
        $query->orderBy($column, $direction)->orderBy('id');

        return $this->applyFilters($query, $game, $filters);
    }

    private function applyFilters(Builder $query, Game $game, array $filters): Builder
    {
        if ($mode = $filters['mode'] ?? null) {
            $query->whereHas('modes', fn (Builder $q) => $q->where('game_modes.slug', $mode));
        }

        if ($version = $filters['version'] ?? null) {
            $query->whereHas('version', fn (Builder $q) => $q->where('game_versions.slug', $version));
        }

        if ($country = $filters['country'] ?? null) {
            $query->whereHas('country', fn (Builder $q) => $q->where('countries.slug', $country));
        }

        if (($filters['status'] ?? null) === 'online') {
            $query->online();
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('host', 'like', "%{$search}%");
            });
        }

        return $query;
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
            'modes' => $this->facetRows($game->modes()->active()),
            'versions' => $this->facetRows($game->versions()->active()),
            'countries' => $this->countryFacets($game),
        ];
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
}
