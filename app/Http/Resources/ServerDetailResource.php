<?php

namespace App\Http\Resources;

use App\Models\Server;
use App\Services\Catalog\ServerInfo;
use App\Services\Catalog\ServerLanguage;
use App\Services\Catalog\ServerRanking;
use Illuminate\Http\Request;

/**
 * @mixin Server
 */
class ServerDetailResource extends ServerResource
{
    public function toArray(Request $request): array
    {
        $info = app(ServerInfo::class);

        /*
         * array_merge, not `+`.
         *
         * The union operator keeps the *left* value on a collision, so when the
         * listing resource gained a trimmed `game` — three fields, for the
         * cross-game home page — it silently won here and the full GameResource
         * below was discarded. The detail page reads `game.monitoring.protocol`
         * to decide whether Connect can be a steam:// link, so the payload
         * type-checked, served 200, and threw in the browser.
         *
         * A subclass adding to its parent must be able to override it. `game` is
         * the only key both define, and this is the direction that has to win.
         */
        return array_merge(parent::toArray($request), [
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
            'bots' => $this->bots,
            'vac' => $this->vac_enabled === null ? null : (bool) $this->vac_enabled,
            // Inferred, not reported — see ServerLanguage for what from.
            'language' => app(ServerLanguage::class)->for($this->resource),
            'info' => $info->for($this->resource),
            'standing' => app(ServerRanking::class)->standing($this->resource),
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
            'last_offline_at' => $this->last_offline_at?->toIso8601String(),
        ]);
    }
}
