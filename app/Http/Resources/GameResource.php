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
            'accent_color' => $this->accent_color,
            'icon' => $this->icon_path,
            'cover' => $this->cover_path,
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
