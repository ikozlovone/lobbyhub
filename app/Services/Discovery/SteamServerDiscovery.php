<?php

namespace App\Services\Discovery;

use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server discovery through the Steam Web API.
 *
 * `IGameServersService/GetServerList` returns every registered server for an app
 * id together with its metadata, in one request. It replaced the legacy UDP
 * master server, whose hostname no longer resolves at all.
 *
 * Two limits shape how this is used, both measured rather than assumed:
 *  - one request returns at most 10 000 servers, whatever `limit` asks for
 *  - roughly 70% of Rust servers are empty, so the `\empty\1` filter cuts the
 *    result to the servers anyone would actually browse
 */
class SteamServerDiscovery
{
    private const ENDPOINT = 'https://api.steampowered.com/IGameServersService/GetServerList/v1/';

    /** The API caps a response here regardless of what `limit` says. */
    public const MAX_PER_REQUEST = 10_000;

    /**
     * @return Collection<int, DiscoveredServer>
     */
    public function discover(Game $game, bool $includeEmpty = false): Collection
    {
        if ($game->steam_appid === null) {
            throw new RuntimeException("Game [{$game->slug}] has no Steam app id");
        }

        $key = (string) config('services.steam.key');

        if ($key === '') {
            throw new RuntimeException('STEAM_API_KEY is not set');
        }

        $filter = '\appid\\'.$game->steam_appid.($includeEmpty ? '' : '\empty\1');

        try {
            $response = Http::timeout(90)->get(self::ENDPOINT, [
                'key' => $key,
                'filter' => $filter,
                'limit' => self::MAX_PER_REQUEST,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Steam API unreachable: {$exception->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException("Steam API returned HTTP {$response->status()}");
        }

        return collect($response->json('response.servers') ?? [])
            ->map(fn (array $row) => DiscoveredServer::fromApi($row))
            ->filter()
            ->values();
    }
}
