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
    /** Counters move every few minutes at most, so a short cache is invisible. */
    private const CACHE_TTL = 60;

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
