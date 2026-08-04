<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every server URL the site has, for the sitemap and nothing else.
 *
 * The catalog listing cannot do this job. It is capped at a hundred pages of a
 * hundred rows, deliberately — deep pagination is a slow query nobody browses
 * to, and a crawlable one dilutes the index with near-duplicate listings. Those
 * are the right limits for a listing and the wrong ones for an enumeration: the
 * catalog passed twenty thousand servers a while ago, so a sitemap built on it
 * would silently stop at ten thousand and the rest would never be submitted.
 *
 * So: its own endpoint, its own limits, and a row carrying two fields rather
 * than the forty a listing needs. Read once per sitemap cache window by our own
 * frontend, which is why it can afford a page size a listing never could.
 */
class SitemapController extends Controller
{
    /** Rows per page. High because the caller wants all of them, and cached at that. */
    private const MAX_PER_PAGE = 50000;

    private const DEFAULT_PER_PAGE = 25000;

    public function servers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        /*
         * `verified`, not merely `active`.
         *
         * A server we have written down but never reached has a page that
         * answers, and no listing links to it — Server::scopeVerified is what
         * keeps it out of them until our own query confirms it exists. Putting
         * it in the sitemap would be submitting a page the site itself does not
         * consider real yet, and orphaned thin pages are what a catalog domain
         * gets marked down for. It arrives here the moment it is reached.
         *
         * Ordered by id, which is stable under insertion in a way `slug` and
         * every measured column are not: page 2 must not hand back rows page 1
         * already had because something was renamed between the two requests.
         */
        $servers = Server::query()
            ->active()
            ->verified()
            ->orderBy('id')
            ->paginate($perPage, ['id', 'slug', 'wiped_at', 'details_synced_at', 'created_at'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $servers->getCollection()->map(fn (Server $server) => [
                'slug' => $server->slug,
                'lastmod' => $this->lastModified($server)?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $servers->currentPage(),
                'last_page' => $servers->lastPage(),
                'per_page' => $servers->perPage(),
                'total' => $servers->total(),
            ],
        ]);
    }

    /**
     * When this server's page last said something different.
     *
     * Not `last_queried_at`, and not `updated_at`, which the monitor touches on
     * every poll. A lastmod that moves every five minutes across twenty
     * thousand URLs is not a change signal, it is an invitation to recrawl the
     * whole catalog continuously — and search engines that notice it is never
     * true simply stop believing the field.
     *
     * These three do mean something changed: a wipe regenerates the map, the
     * seed and the size; a details sync is when the description, images and
     * tuning values were re-read; and creation is the floor for a server that
     * has had neither. Player counts move constantly and are not in here on
     * purpose — they are the part of the page nobody reaches us through a
     * search engine to see.
     */
    private function lastModified(Server $server): ?Carbon
    {
        $candidates = array_filter([
            $server->details_synced_at,
            $server->wiped_at,
            $server->created_at,
        ]);

        return array_reduce(
            $candidates,
            fn (?Carbon $latest, Carbon $candidate) => $latest === null || $candidate->greaterThan($latest)
                ? $candidate
                : $latest,
        );
    }
}
