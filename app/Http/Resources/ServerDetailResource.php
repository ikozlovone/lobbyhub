<?php

namespace App\Http\Resources;

use App\Services\Catalog\ServerInfo;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Server
 */
class ServerDetailResource extends ServerResource
{
    public function toArray(Request $request): array
    {
        $info = app(ServerInfo::class);

        return parent::toArray($request) + [
            // The owner's own text wins; otherwise whatever the server publishes.
            'description' => $this->description ?? $info->description($this->resource),
            'host' => $this->host,
            'port' => $this->game_port ?? $this->port,
            // Two different addresses, and players confuse them constantly: one
            // is typed into the game client, the other is what we query.
            'connect_address' => $this->address(),
            'query_address' => $this->host.':'.$this->queryPort(),
            'connect_hostname' => $info->for($this->resource)['connect_hostname'] ?? null,
            'steam_id' => $this->steam_id,
            'info' => $info->for($this->resource),
            'media' => $info->media($this->resource),
            'details_synced_at' => $this->details_synced_at?->toIso8601String(),
            'latency_ms' => $this->latestLatency(),
            'game' => new GameResource($this->whenLoaded('game')),
            'modes' => $this->whenLoaded('modes', fn () => $this->modes->map(fn ($mode) => [
                'slug' => $mode->slug,
                'name' => $mode->name,
            ])),
            'game_version' => $this->whenLoaded('version', fn () => $this->version ? [
                'slug' => $this->version->slug,
                'name' => $this->version->name,
            ] : null),
            'links' => [
                'website' => $this->website_url ?? $info->website($this->resource),
                'discord' => $this->discord_url,
            ],
            'claimed' => $this->isClaimed(),
            'first_seen_at' => $this->created_at?->toIso8601String(),
            'last_online_at' => $this->last_online_at?->toIso8601String(),
        ];
    }
}
