<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Catalog\GameHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The player-count chart: which games are being played right now, and how that
 * has moved.
 *
 * The numbers are Steam's own — every game in the catalog with a `steam_appid`
 * gets its concurrent player count read every ten minutes by the `steamstats`
 * collector, which also writes the history these charts are drawn from. What
 * this endpoint serves is the current value off `games`, denormalised there for
 * exactly this reason: a chart of forty rows should not be forty ClickHouse
 * reads.
 *
 * Deliberately not the same question as the catalog listing next door. That one
 * ranks servers we monitor; this one ranks games, by a number that has nothing
 * to do with whether anybody runs a server for them — which is why a game can
 * lead this chart and have nothing in the listing at all.
 */
class GameChartController extends Controller
{
    /** How long the assembled chart is worth keeping. The collector ticks every ten minutes. */
    private const CACHE_TTL = 120;

    public function index(): JsonResponse
    {
        $payload = Cache::remember('api:charts', self::CACHE_TTL, function () {
            $games = Game::query()
                ->where('is_active', true)
                ->whereNotNull('steam_appid')
                // Never measured is not "zero players": it is a game the
                // collector has not reached yet, and a chart is a ranking —
                // an unmeasured row at the bottom claims a position it has
                // not earned.
                ->whereNotNull('steam_stats_synced_at')
                ->orderByDesc('steam_players_online')
                ->orderBy('sort_order')
                ->get();

            return [
                'data' => $games->values()->map(fn (Game $game, int $index) => [
                    'position' => $index + 1,
                    'slug' => $game->slug,
                    'name' => $game->name,
                    'icon' => $game->icon_path ? asset($game->icon_path) : null,
                    'accent_color' => $game->accent_color,
                    'steam_appid' => $game->steam_appid,
                    'players' => (int) $game->steam_players_online,
                    'peak' => (int) $game->steam_players_peak,
                    // Where Steam itself puts the game in its top 100, which is
                    // not the same as its position here: this chart only ranks
                    // the games we carry.
                    'steam_rank' => $game->steam_chart_rank,
                    // The other half of the site, and the reason a visitor who
                    // arrives here has somewhere to go.
                    'servers' => (int) $game->servers_count,
                    'servers_online' => (int) $game->online_servers_count,
                    'server_players' => (int) $game->players_online,
                ])->all(),
                'meta' => [
                    'games' => $games->count(),
                    'players' => (int) $games->sum('steam_players_online'),
                    'charted' => $games->whereNotNull('steam_chart_rank')->count(),
                    'synced_at' => $games->max('steam_stats_synced_at')?->toIso8601String(),
                ],
            ];
        });

        return response()->json($payload);
    }

    /** One game's series, for the chart on its own page. */
    public function history(Request $request, Game $game, GameHistory $history): JsonResponse
    {
        abort_unless($game->is_active && $game->steam_appid !== null, 404);

        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(GameHistory::ranges())],
        ]);

        $range = $validated['range'] ?? '24h';

        // The heaviest read here, same as the server chart's: a burst of page
        // views collapses into one ClickHouse query.
        $payload = Cache::remember(
            "api:games:{$game->id}:players:{$range}",
            600,
            fn () => $history->for($game, $range),
        );

        return response()->json(['data' => $payload]);
    }
}
