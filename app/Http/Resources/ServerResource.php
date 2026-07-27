<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in a listing. Everything here is safe to cache in a static page except
 * the `live` block, which the frontend refreshes on its own.
 *
 * @mixin \App\Models\Server
 */
class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'motd' => $this->motd,
            // What a player connects to — reported by the server when it can.
            'address' => $this->address(),
            'map' => $this->map,
            'version' => $this->reported_version,
            'country' => $this->whenLoaded('country', fn () => [
                'code' => $this->country?->code,
                'name' => $this->country?->name,
                'slug' => $this->country?->slug,
            ]),
            'banner' => $this->banner_path,
            'icon' => $this->icon_path,
            'votes' => $this->votes_count,
            'rating' => $this->rating_avg === null ? null : (float) $this->rating_avg,
            'promoted' => $this->isPromoted(),
            'wiped_at' => $this->wiped_at?->toIso8601String(),
            'live' => [
                'status' => $this->status->value,
                'players' => $this->players_online,
                'max_players' => $this->players_max,
                'queued' => $this->players_queued,
                'uptime' => $this->uptime_percent === null ? null : (float) $this->uptime_percent,
                'checked_at' => $this->last_queried_at?->toIso8601String(),
            ],
        ];
    }
}
