<?php

namespace App\Services\Catalog;

use App\Models\Game;
use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;

/**
 * The rendered listing, kept for a minute and dropped when the game changes.
 *
 * What is cached is the finished payload — rows, links and meta, exactly as the
 * endpoint would have answered — and not the models behind it. The query is no
 * longer the expensive half: with the partial indexes in place a page is an
 * index scan of twenty-five rows, and hydrating those rows into Eloquent models
 * and running them through ServerResource costs about as much again. Caching the
 * builder's output would leave that half on the table. `ServerResource::toArray`
 * never reads the request, so the array it produces is a pure function of the
 * rows, which is what makes this safe.
 *
 * Two things expire an entry, and they answer different complaints. The window
 * covers drift — player counts, statuses, votes, anything that moves on its own
 * — and it can be short because the frontend overlays the live numbers on top of
 * whatever it was given (see Мониторинг.md §15.1), so a minute-old listing is
 * the intended reading rather than a stale one. The tags cover events: somebody
 * added a server and came back to look for it, and that person must not be told
 * to wait out a window. Same pairing as the frontend's own cache in §17.
 */
class ListingCache
{
    /**
     * Ten minutes, and the sort is what makes that safe rather than reckless.
     *
     * A listing is asked for as `sort=rank` unless somebody chooses otherwise,
     * and `rank_score` is rewritten by `ranking:recompute` every fifteen
     * minutes — so the order this window holds could not have changed inside it
     * anyway. What does move underneath is the player counts and statuses, and
     * those are overlaid in the browser from /servers/live rather than read
     * from here (Мониторинг.md §15.1).
     *
     * It was a minute before, which also made caching `total` separately worth
     * doing: the count is the one part of a listing that is linear in the size
     * of the game, and membership moves far more slowly than rows do. At ten
     * minutes that separation buys nothing — the count is inside this entry and
     * gets the same window.
     *
     * What the window is emphatically not responsible for is somebody adding a
     * server and coming back to look for it. That is the tags' job, below.
     */
    private const TTL = 600;

    /** Carried by every entry, so there is one lever that clears all of them. */
    private const TAG = 'listing';

    /**
     * @param  array<string, mixed>  $filters  as validated by the controller
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function remember(?Game $game, array $filters, Closure $build): array
    {
        if (! $this->taggable() || ! $this->cacheable($filters)) {
            return $build();
        }

        return Cache::tags($this->tags($game))
            ->remember($this->key($game, $filters), self::TTL, $build);
    }

    /**
     * A game's facet counts, which are the expensive half of its page.
     *
     * Five aggregates, three of them linear in the size of the game — the
     * status buckets, the country breakdown and the map list. They used to ride
     * inside the game's own payload, on a ten-minute window that never got to
     * apply: CatalogCounters::refresh() forgot that key every minute so the
     * counters beside them would stay current, and took the facets with it. A
     * cold Counter-Strike paid 1.74 seconds for the privilege, once a minute.
     *
     * Separated because the two halves move at different speeds. The counters
     * are three denormalised columns on the game row, already in memory by the
     * time this is called, and they stay exactly as fresh as they were. The
     * facets get a real window and the same tags as the listings, so a game
     * that gains or loses servers drops both together.
     *
     * The cost is the status chips: online, offline, empty and full are counted
     * across the whole game (§15.5), and they now lag by up to the window. The
     * chips answer "what does this game look like", which is a question that
     * tolerates ten minutes; the numbers a visitor watches move are on the rows
     * and come from the live layer.
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function facets(Game $game, Closure $build): array
    {
        if (! $this->taggable()) {
            return $build();
        }

        return Cache::tags($this->tags($game))
            ->remember(self::TAG.":facets:{$game->slug}", self::TTL, $build);
    }

    /**
     * Drop the listings of games that changed shape, and the cross-game one.
     *
     * Per game rather than wholesale: the catalog gains and loses servers all
     * day, and one game's discovery run is no reason to make every other game's
     * listing be rebuilt. The cross-game listing spans all of them, so it goes
     * whenever any of them did.
     *
     * @param  list<string>  $slugs
     */
    public function forget(array $slugs): void
    {
        if ($slugs === [] || ! $this->taggable()) {
            return;
        }

        Cache::tags(array_map(fn (string $slug) => self::TAG.":{$slug}", $slugs))->flush();
        Cache::tags([self::TAG.':catalog'])->flush();
    }

    /** Everything, for when the shape of the payload itself has changed. */
    public function flush(): void
    {
        if (! $this->taggable()) {
            return;
        }

        Cache::tags([self::TAG])->flush();
    }

    /**
     * Nothing is cached unless the store can be invalidated.
     *
     * `Cache::tags()` throws outright on the file and database stores, so
     * without this the listing endpoints — the two busiest the API has — answer
     * 500 the moment CACHE_STORE names one of them. That is a plausible thing
     * for it to name: rolling the store back is one line in `.env`, and the
     * whole point of a rollback is that it cannot make things worse.
     *
     * Degrading to no cache is the right failure. An untagged cache is not:
     * `CatalogCounters::refresh()` would have no way to drop a listing, and a
     * server somebody added would stay missing for the length of the window
     * rather than appearing when they came back to look.
     */
    private function taggable(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Searches are not cached.
     *
     * `q` is free text, so the keyspace it opens has no bound and almost every
     * entry in it is written once and never read — a crawler walking the search
     * box would fill the store with them and, under `allkeys-lru`, push out the
     * listings that do get read. The listing endpoints are what this exists for;
     * search is a different access pattern and is left to the indexes.
     *
     * @param  array<string, mixed>  $filters
     */
    private function cacheable(array $filters): bool
    {
        return trim((string) ($filters['q'] ?? '')) === '';
    }

    /**
     * The page comes from the paginator's own resolver, not from `$filters`.
     *
     * `page` is a `sometimes` rule, so it is simply absent from the validated
     * array when the visitor is on page one — and `ServerListing::paginate`
     * reads it from the request regardless. Keyed off the filters alone, page
     * two and page one would be the same entry, and the second one asked for
     * would be served the first one's rows.
     *
     * @param  array<string, mixed>  $filters
     */
    private function key(?Game $game, array $filters): string
    {
        unset($filters['page']);
        ksort($filters);

        return sprintf(
            '%s:%s:%d:%s',
            self::TAG,
            $game?->slug ?? 'catalog',
            Paginator::resolveCurrentPage(),
            md5((string) json_encode($filters)),
        );
    }

    /** @return list<string> */
    private function tags(?Game $game): array
    {
        return [self::TAG, self::TAG.':'.($game?->slug ?? 'catalog')];
    }
}
