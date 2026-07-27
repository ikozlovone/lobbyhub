<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Game
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
            'icon' => $this->icon_path ? asset($this->icon_path) : null,
            'cover' => $this->cover_path ? asset($this->cover_path) : null,
            'has_versions' => $this->has_versions,
            'counters' => [
                'servers' => $this->servers_count,
                'servers_online' => $this->online_servers_count,
                'players_online' => $this->players_online,
                'synced_at' => $this->stats_synced_at?->toIso8601String(),
            ],
            'seo' => [
                'title' => $this->meta_title,
                'description' => $this->meta_description,
            ],
            'description' => $this->when($request->routeIs('api.games.show'), $this->description),
        ];
    }
}
