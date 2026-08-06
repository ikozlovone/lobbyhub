<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Models\Game;
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

    public function show(Request $request, Game $game, ServerListing $listing): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $payload = Cache::remember(
            "api:games:{$game->id}",
            self::CACHE_TTL,
            fn () => (new GameResource($game))->toArray($request) + [
                'facets' => $listing->facets($game),
            ],
        );

        return response()->json(['data' => $payload]);
    }
}
