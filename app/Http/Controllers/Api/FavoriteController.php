<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The servers one account wants to find again.
 *
 * Nothing here is cached, at any layer. Every other listing on this site is a
 * shared page that can afford to be a minute old; this one is a private answer
 * to "where was I playing", read straight from the database on each request. A
 * stale favourite list is not a slightly old listing — it is somebody's own data
 * disagreeing with what they just did.
 */
class FavoriteController extends Controller
{
    /**
     * Everything this account starred, in blocks by game.
     *
     * Grouped here rather than in the browser because the grouping carries the
     * game's own name, artwork and colour, and shipping those once per block is
     * the difference between a payload that repeats itself forty times and one
     * that does not. The order is the catalog's own: games as the sidebar has
     * them, and inside a game the newest star first.
     */
    public function index(Request $request): JsonResponse
    {
        $servers = $request->user()
            ->favorites()
            // sort_order is selected because the blocks are ordered by it, not
            // because anything renders it — leave it out and every game sorts
            // equal, which reads as "unordered" rather than as a bug.
            ->with([
                'game:id,slug,name,accent_color,cover_path,query_protocol,sort_order',
                'country:id,code,name,slug',
                'version:id,slug,name',
            ])
            // Delisted or soft-deleted servers stay starred but are not shown:
            // the row is kept so that a server coming back brings its stars with
            // it, and nobody has to notice it went.
            ->where('servers.is_active', true)
            ->get();

        $groups = $servers
            ->groupBy(fn (Server $server) => $server->game->slug)
            ->sortBy(fn ($group) => $group->first()->game->sort_order)
            ->map(fn ($group) => [
                'game' => [
                    'slug' => $group->first()->game->slug,
                    'name' => $group->first()->game->name,
                    'accent_color' => $group->first()->game->accent_color,
                    'cover' => $group->first()->game->cover_path ? asset($group->first()->game->cover_path) : null,
                    // The connect buttons differ per protocol, exactly as they do
                    // in a game listing.
                    'protocol' => $group->first()->game->query_protocol->value,
                ],
                'servers' => ServerResource::collection($group->values())->toArray($request),
            ])
            ->values();

        return response()->json([
            'data' => $groups,
            'meta' => ['total' => $servers->count()],
        ]);
    }

    /** Starring something already starred is not an error; it is the same star. */
    public function store(Request $request, Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $request->user()->favorites()->syncWithoutDetaching([
            $server->id => ['created_at' => now()],
        ]);

        return response()->json(['data' => ['favorited' => true]], 201);
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $request->user()->favorites()->detach($server->id);

        return response()->json(['data' => ['favorited' => false]]);
    }
}
