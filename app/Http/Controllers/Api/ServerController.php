<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerDetailResource;
use App\Http\Resources\ServerResource;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\FrontendCache;
use App\Services\Catalog\ServerListing;
use App\Services\Monitoring\ServerQueryManager;
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

    /**
     * How often a manual refresh may actually re-query one server.
     *
     * Half the hottest polling tier, so at worst a server somebody is watching
     * gets checked twice as often as our own schedule already checks it — a
     * bounded amount of extra traffic aimed at a machine we do not own.
     */
    private const REFRESH_COOLDOWN = 60;

    public function index(Request $request, Game $game, ServerListing $listing): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $filters = $request->validate([
            'mode' => ['sometimes', 'string', 'max:64'],
            'version' => ['sometimes', 'string', 'max:64'],
            'country' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(ServerListing::statuses())],
            // The map name as the server reports it, which is what the facet
            // hands back — there is no slug to match against.
            'map' => ['sometimes', 'string', 'max:120'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(ServerListing::sorts())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        $servers = $listing->paginate($game, $filters);

        return ServerResource::collection($servers)->response();
    }

    /**
     * The same listing, across every game.
     *
     * What the home page is built from: "the busiest servers", "the newest",
     * "the ones wiped this week" are all one query with a different sort, and
     * assembling them in the frontend would mean a request per game — 46 today,
     * more with every game added.
     *
     * Deliberately not paginated past the first page: this feeds sections of
     * six to twelve rows, and a crawlable, deep, cross-game listing is exactly
     * the thin near-duplicate index the per-game one already caps.
     */
    public function catalog(Request $request, ServerListing $listing): JsonResponse
    {
        $filters = $request->validate([
            'game' => ['sometimes', 'string', 'max:64'],
            'country' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(ServerListing::statuses())],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(ServerListing::sorts())],
            // Days back to count as "recently wiped"; absent means no filter.
            'wiped' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        $servers = $listing->paginate(null, $filters);

        return ServerResource::collection($servers)->response();
    }

    public function show(Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $server->load(['game', 'country', 'version', 'modes']);

        return (new ServerDetailResource($server))->response();
    }

    /**
     * Query this server again, now, because somebody asked.
     *
     * Run inline rather than queued: the caller is a person looking at the panel
     * waiting for it to change, and handing them a job id to poll for would be a
     * worse version of waiting. One query is a socket and a five-second timeout.
     *
     * Two guards, doing different jobs. The IP limiter on the route stops one
     * client walking the catalog. The cooldown here is per *server*, so no
     * number of clients can make us knock on one address more often than this —
     * that is the guard that matters, because the address belongs to someone
     * else. Inside the cooldown the request is still answered, with the current
     * snapshot and `refreshed: false`: the panel updates either way, and "we
     * checked forty seconds ago" is a better answer than an error.
     */
    public function refresh(Server $server, ServerQueryManager $manager, FrontendCache $frontend): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $server->load(['game', 'country', 'version', 'modes']);

        $due = $server->last_queried_at === null
            || $server->last_queried_at->addSeconds(self::REFRESH_COOLDOWN)->isPast();

        if ($due && $manager->supports($server->game->query_protocol)) {
            // With the slow-moving facts, not only the player count: this button
            // sits inside the panel those fill, and the person pressing it is
            // asking about what they are looking at.
            QueryServer::dispatchSync($server, null, true);

            $server->refresh()->load(['game', 'country', 'version', 'modes']);

            // The panel updates itself from this response, but the page around
            // it is a shell cached for minutes — reload before it expires and
            // the map, the facts and the history graph are all back to what they
            // were, which reads as a refresh that did not save. Nothing else
            // revalidates a server: the monitor's own polls are what the live
            // layer covers, and doing this for those would expire every server
            // page in the catalog every few minutes.
            $frontend->invalidate("server:{$server->slug}");
        }

        return (new ServerDetailResource($server))
            ->additional(['refreshed' => $due])
            ->response();
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
