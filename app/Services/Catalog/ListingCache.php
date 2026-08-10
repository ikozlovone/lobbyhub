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
     * Long enough to collapse a burst of identical requests — a chip pressed,
     * the same page shared, a listing crawled — and short enough that the
     * things tags do not catch, like a server going offline or being renamed,
     * are never wrong for long.
     */
    private const TTL = 60;

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
