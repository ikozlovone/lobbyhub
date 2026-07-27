<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerDetailResource;
use App\Http\Resources\ServerResource;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\ServerListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    /**
     * Deep pagination is capped rather than left open: page 500 is a slow query
     * that nobody browses to, and letting search engines crawl it dilutes the
     * index with near-duplicate listings.
     */
    private const MAX_PAGE = 100;

    public function index(Request $request, Game $game, ServerListing $listing): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $filters = $request->validate([
            'mode' => ['sometimes', 'string', 'max:64'],
            'version' => ['sometimes', 'string', 'max:64'],
            'country' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(['online'])],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(ServerListing::sorts())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        $servers = $listing->paginate($game, $filters);

        return ServerResource::collection($servers)->response();
    }

    public function show(Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $server->load(['game', 'country', 'version', 'modes']);

        return (new ServerDetailResource($server))->response();
    }

    /**
     * The live half of the two-layer page: static shells are cached for hours,
     * this endpoint keeps the numbers on them honest. Deliberately tiny and
     * uncached so a page can poll it at the monitoring cadence.
     */
    public function live(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slugs' => ['required', 'string', 'max:2000'],
        ]);

        $slugs = collect(explode(',', $validated['slugs']))
            ->map(fn (string $slug) => trim($slug))
            ->filter()
            ->unique()
            ->take(100);

        $servers = Server::query()
            ->active()
            ->whereIn('slug', $slugs)
            ->get(['slug', 'status', 'players_online', 'players_max', 'players_queued', 'last_queried_at']);

        return response()->json([
            'data' => $servers->map(fn (Server $server) => [
                'slug' => $server->slug,
                'status' => $server->status->value,
                'players' => $server->players_online,
                'max_players' => $server->players_max,
                'queued' => $server->players_queued,
                'checked_at' => $server->last_queried_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
