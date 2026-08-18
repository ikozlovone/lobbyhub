<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in a listing. Everything here is safe to cache in a static page except
 * the `live` block, which the frontend refreshes on its own.
 *
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Every listing loads this — the resource cannot render without hot
        // fields, and lazy-loading here would cross partitions once per row.
        $state = $this->state;

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'motd' => $state?->motd,
            /**
             * Only present on the catalog-wide listing, which is the only one
             * that eager-loads it — inside a game the caller already knows.
             */
            'game' => $this->whenLoaded('game', fn () => [
                'slug' => $this->game->slug,
                'name' => $this->game->name,
                'protocol' => $this->game->query_protocol->value,
            ]),
            // What a player connects to — reported by the server when it can.
            'address' => $this->address(),
            'map' => $state?->map,
            'version' => $state?->reported_version,
            'country' => $this->whenLoaded('country', fn () => [
                'code' => $this->country?->code,
                'name' => $this->country?->name,
                'slug' => $this->country?->slug,
            ]),
            'city' => $this->city,
            'banner' => $this->banner_path,
            'icon' => $this->icon_path,
            'votes' => $this->votes_count,
            'rating' => $this->rating_avg === null ? null : (float) $this->rating_avg,
            'promoted' => $this->isPromoted(),
            'wiped_at' => $state?->wiped_at?->toIso8601String(),
            /** When the catalog first got this address — what "latest added" sorts on. */
            'added_at' => $this->created_at?->toIso8601String(),
            'live' => [
                'status' => $state?->status?->value,
                'players' => $state?->players_online ?? 0,
                'max_players' => $state?->players_max ?? 0,
                'queued' => $state?->players_queued ?? 0,
                'uptime' => $state?->uptime_percent === null ? null : (float) $state->uptime_percent,
                'checked_at' => $state?->last_queried_at?->toIso8601String(),
            ],
        ];
    }
}
