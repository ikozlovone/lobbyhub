<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerDetailResource;
use App\Http\Resources\ServerResource;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\ListingCache;
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

    public function index(
        Request $request,
        Game $game,
        ServerListing $listing,
        ListingCache $cache,
    ): JsonResponse {
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

        return response()->json($cache->remember(
            $game,
            $filters,
            fn () => ServerResource::collection($listing->paginate($game, $filters))
                ->response()
                ->getData(true),
        ));
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
    public function catalog(Request $request, ServerListing $listing, ListingCache $cache): JsonResponse
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

        return response()->json($cache->remember(
            null,
            $filters,
            fn () => ServerResource::collection($listing->paginate(null, $filters))
                ->response()
                ->getData(true),
        ));
    }

    public function show(Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        // `state` with a `game_id` predicate so the query prunes to one
        // partition instead of scanning every game.
        $server->load([
            'game', 'country', 'version', 'modes',
            'state' => fn ($q) => $q->where('game_id', $server->game_id),
        ]);

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
    public function refresh(Server $server, ServerQueryManager $manager): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $server->load([
            'game', 'country', 'version', 'modes',
            'state' => fn ($q) => $q->where('game_id', $server->game_id),
        ]);

        // `last_queried_at` lives on state now; the cooldown is judged off it.
        $lastQueried = $server->state?->last_queried_at;
        $due = $lastQueried === null || $lastQueried->addSeconds(self::REFRESH_COOLDOWN)->isPast();

        if ($due && $manager->supports($server->game->query_protocol)) {
            // With the slow-moving facts, not only the player count: this button
            // sits inside the panel those fill, and the person pressing it is
            // asking about what they are looking at.
            QueryServer::dispatchSync($server, null, true);

            $server->refresh()->load([
                'game', 'country', 'version', 'modes',
                'state' => fn ($q) => $q->where('game_id', $server->game_id),
            ]);
        }

        return (new ServerDetailResource($server))
            ->additional(['refreshed' => $due])
            ->response();
    }

    /**
     * The live half of the two-layer page. Pages arrive with correct numbers on
     * them; this is what keeps a page somebody left open from drifting.
     * Deliberately tiny and uncached so it can be polled at the monitoring
     * cadence by every listing on the site.
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

        // JOIN state with both `server_id` AND `game_id` so Postgres can
        // prune. For a mixed-game slug list every relevant partition is
        // visited exactly once — one per game — instead of every partition
        // being scanned once per slug.
        $rows = Server::query()
            ->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->whereIn('servers.slug', $slugs)
            ->get([
                'servers.slug',
                'server_states.status',
                'server_states.players_online',
                'server_states.players_max',
                'server_states.players_queued',
                'server_states.last_queried_at',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'slug' => $row->slug,
                'status' => $row->status,
                'players' => (int) $row->players_online,
                'max_players' => (int) $row->players_max,
                'queued' => (int) $row->players_queued,
                'checked_at' => $row->last_queried_at
                    ? \Illuminate\Support\Carbon::parse($row->last_queried_at)->toIso8601String()
                    : null,
            ])->all(),
        ]);
    }
}
