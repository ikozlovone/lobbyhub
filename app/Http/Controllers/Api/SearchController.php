<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])['q']);

        return response()->json([
            'data' => [
                'games' => $this->games($term),
                'servers' => $this->servers($term),
            ],
        ]);
    }

    /**
     * Matches the display name and the alias list, so "гта рп" or "mc" find
     * the right game — that is what `games.aliases` is for.
     */
    private function games(string $term): array
    {
        return Game::query()
            ->active()
            ->get(['slug', 'name', 'short_name', 'aliases', 'servers_count'])
            ->filter(function (Game $game) use ($term) {
                $haystack = collect([$game->name, $game->short_name])
                    ->merge($game->aliases ?? [])
                    ->filter()
                    ->map(fn (string $value) => mb_strtolower($value));

                return $haystack->contains(fn (string $value) => str_contains($value, mb_strtolower($term)));
            })
            ->take(5)
            ->map(fn (Game $game) => [
                'slug' => $game->slug,
                'name' => $game->name,
                'servers_count' => $game->servers_count,
            ])
            ->values()
            ->all();
    }

    private function servers(string $term): array
    {
        return Server::query()
            ->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->where('server_states.status', '!=', \App\Enums\ServerStatus::Unknown->value)
            ->where(fn ($query) => $query
                ->where('servers.name', 'like', "%{$term}%")
                ->orWhere('servers.host', 'like', "%{$term}%"))
            ->orderByDesc('server_states.players_online')
            ->limit(10)
            ->with('game:id,slug,name')
            ->select([
                'servers.id',
                'servers.slug',
                'servers.name',
                'servers.game_id',
                'server_states.players_online',
                'server_states.status',
            ])
            ->get()
            ->map(fn ($server) => [
                'slug' => $server->slug,
                'name' => $server->name,
                'game' => $server->game->slug,
                'players' => (int) $server->players_online,
                'status' => $server->status,
            ])
            ->all();
    }
}
