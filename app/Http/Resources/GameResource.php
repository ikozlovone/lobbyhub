<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Game
 */
class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'short_name' => $this->short_name,
            // Exposed so the frontend can match them without a round trip:
            // "mc" and "майнкрафт" both have to find Minecraft.
            'aliases' => $this->aliases ?? [],
            'accent_color' => $this->accent_color,
            // Absolute: the frontend runs on its own origin and cannot resolve
            // a path relative to the API.
            /*
             * Three pictures, three jobs — see the hero_path migration.
             *
             *   icon  — the thumbnail, drawn at 28px in the rail
             *   cover — the card in the games list
             *   hero  — the banner across the top of a game page
             *
             * `hero` is the only one that may be missing while a game still
             * looks finished: the frontend falls back to `cover` for it, which
             * is what every game did before there was a third.
             */
            'icon' => $this->icon_path ? asset($this->icon_path) : null,
            'cover' => $this->cover_path ? asset($this->cover_path) : null,
            'hero' => $this->hero_path ? asset($this->hero_path) : null,
            'has_versions' => $this->has_versions,
            // What the submission form needs to tell an owner what we are about
            // to do to their server, and which port to expect us on.
            'monitoring' => [
                'protocol' => $this->query_protocol->value,
                'protocol_label' => $this->query_protocol->label(),
                'default_port' => $this->default_port,
                'default_query_port' => $this->default_query_port,
            ],
            'counters' => [
                'servers' => $this->servers_count,
                'servers_online' => $this->online_servers_count,
                'players_online' => $this->players_online,
                'synced_at' => $this->stats_synced_at?->toIso8601String(),
            ],
            /*
             * What Steam says about the game itself, which is a different
             * number from `counters` above and not a superset of it: that one
             * counts players our monitor found on the game's servers, this one
             * counts everybody in the game anywhere on Steam. Written by the
             * `steamstats` collector; null `synced_at` means it has not reached
             * this game yet, which is not the same as nobody playing.
             */
            'steam' => [
                'players_online' => (int) $this->steam_players_online,
                'players_peak' => (int) $this->steam_players_peak,
                'chart_rank' => $this->steam_chart_rank,
                'synced_at' => $this->steam_stats_synced_at?->toIso8601String(),
            ],
            'seo' => [
                'title' => $this->meta_title,
                'description' => $this->meta_description,
            ],
            'description' => $this->when($request->routeIs('api.games.show'), $this->description),
            // Only the game's own page shows these, and most games have none —
            // no reason to carry them through the index that every page loads.
            'links' => $this->when($request->routeIs('api.games.show'), $this->links ?? []),
        ];
    }
}
