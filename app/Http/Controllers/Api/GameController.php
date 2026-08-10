<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\Catalog\ListingCache;
use App\Services\Catalog\ServerListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GameController extends Controller
{
    /*
     * Facets, not counters — that is what makes the ceiling worth raising.
     *
     * The live numbers people watch (players online, whether a server is up)
     * ride on their own live layer on every page, so a ten-minute cache here
     * does not stale them. What is cached here are the chip lists — map,
     * country, mode, version and status splits — which change when servers
     * come and go, not by the second, and can cost seconds to recompute for a
     * game with a hundred thousand rows. Sixty seconds meant every visitor
     * after a minute of quiet paid the whole bill; ten minutes leaves the hit
     * to deploys and cache flushes, which is what an admin adjusting content
     * already expects.
     */
    private const CACHE_TTL = 600;

    /**
     * Cached values are always plain arrays. Putting Eloquent objects in the
     * cache stores model state and has to rebuild live models on every hit.
     */
    public function index(Request $request): JsonResponse
    {
        $games = Cache::remember(
            'api:games',
            self::CACHE_TTL,
            fn () => GameResource::collection(
                Game::query()->active()->orderBy('sort_order')->get()
            )->toArray($request),
        );

        return response()->json(['data' => $games]);
    }

    /**
     * The game, and the facet counts its page filters by.
     *
     * Facets are read straight off the game row when the scheduled
     * `facets:refresh` has left them there, which is the common case: two
     * caches in front of this endpoint already mask most of the cost, but
     * between them lies a moment when both have expired and the next visitor
     * pays the whole aggregate on cold Postgres. The column removes that
     * moment. The Redis fallback stays for the sliver of the day when the
     * schedule has not caught a game — right after a game is added, right
     * after a server changes shape and the column is cleared — and computes
     * once, caches, then the next schedule tick fills the column again.
     */
    public function show(Request $request, Game $game, ServerListing $listing, ListingCache $cache): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $facets = $game->facets
            ?? $cache->facets($game, fn () => $listing->facets($game));

        return response()->json([
            'data' => (new GameResource($game))->toArray($request) + [
                'facets' => $facets,
            ],
        ]);
    }
}
