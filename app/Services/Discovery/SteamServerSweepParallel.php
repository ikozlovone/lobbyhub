<?php

namespace App\Services\Discovery;

use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The same sweep, level-parallel: siblings ride Http::pool instead of a stack.
 *
 * BFS instead of DFS. A bucket is still expanded only when it comes back
 * saturated, so a game that fits in one request stays at one request; what
 * changes is that every still-full bucket at a given depth is asked at once.
 * On a wide game — Counter-Strike, DayZ — that turns a hundred sequential
 * round-trips into a handful of wall-clock ones.
 *
 * Chunked, and this is not tidiness. Http::pool waits for every request in a
 * batch to complete before it returns, so the peak memory is the pool size
 * times a saturated response — ten megabytes each. A wide level unchunked is
 * hundreds of megabytes held live; the chunk caps that.
 */
class SteamServerSweepParallel implements ServerSweep
{
    private const ENDPOINT = 'https://api.steampowered.com/IGameServersService/GetServerList/v1/';

    private function saturatedAt(): int
    {
        return (int) config('monitoring.steam_saturated_at', 9_000);
    }

    private function poolSize(): int
    {
        return max(1, (int) config('monitoring.steam_pool_size', 10));
    }

    /**
     * @param  callable(DiscoveredServer): void  $onServer
     * @param  array<string, mixed>|null  $only  Addresses (`ip:gameport`) worth
     *                                           building; null takes everything. See SteamServerSweep::stream.
     * @param  (callable(int, int, float): void)|null  $onLevel  Called once per
     *                                                           level with (level index, requests fired, wall milliseconds). Level
     *                                                           0 is the sequential root fetch; 1+ are the parallel expansions.
     */
    public function stream(
        Game $game,
        callable $onServer,
        bool $populatedOnly = false,
        ?array $only = null,
        ?callable $onLevel = null,
    ): SweepResult {
        if ($game->steam_appid === null) {
            throw new RuntimeException("Game [{$game->slug}] has no Steam app id");
        }

        $keys = $this->keys();
        $seen = [];
        $requests = 0;
        $truncated = 0;
        $unreachable = 0;
        $skipped = 0;
        $httpMs = 0.0;

        $rootFilter = '\appid\\'.$game->steam_appid.($populatedOnly ? SteamServerSweep::POPULATED : '');
        $axes = $populatedOnly ? SteamServerSweep::populatedAxes() : SteamServerSweep::AXES;

        /*
         * Level 0 sequentially, and left to throw.
         *
         * The same rule the sequential sweep has: if the top of the tree
         * cannot be reached there is nothing under it to salvage, and no
         * reason to fan out and prove it a hundred times over.
         */
        $levelStart = microtime(true);
        $rows = $this->request($keys[0], $rootFilter, $keys, $httpMs);
        $requests++;
        $returned = count($rows);
        $this->emit($rows, $seen, $onServer, $only, $skipped);
        unset($rows);
        if ($onLevel !== null) {
            $onLevel(0, 1, (microtime(true) - $levelStart) * 1000);
        }

        if ($returned < $this->saturatedAt()) {
            return new SweepResult(count($seen), $requests, 0, 0, $skipped, $httpMs);
        }

        $saturated = [$rootFilter];
        $levelIndex = 1;

        while ($saturated !== [] && $axes !== []) {
            $axis = array_shift($axes);
            $isLastAxis = $axes === [];

            $filters = [];
            foreach ($saturated as $parent) {
                foreach ($axis as $fragment) {
                    $filters[] = $parent.$fragment;
                }
            }

            $saturated = [];
            $levelStart = microtime(true);
            $levelRequests = 0;

            foreach (array_chunk($filters, $this->poolSize()) as $chunk) {
                $offset = $requests;

                /*
                 * The wall time of the batch, not the sum of its requests.
                 *
                 * Ten requests answering in a second each are one second of
                 * waiting here, and reporting ten would make the parallel sweep
                 * look slower than the sequential one it just beat. What this
                 * number answers is "how long was this process waiting on
                 * Steam", which is the only form of it that can be compared
                 * between the two shapes.
                 */
                $poolStart = hrtime(true);

                $responses = Http::pool(function (Pool $pool) use ($chunk, $keys, $offset) {
                    $calls = [];

                    foreach ($chunk as $i => $filter) {
                        $calls[] = $pool->as((string) $i)
                            ->timeout(90)
                            ->retry(3, 1000, throw: false)
                            ->get(self::ENDPOINT, [
                                'key' => $keys[($offset + $i) % count($keys)],
                                'filter' => $filter,
                                'limit' => SteamServerSweep::CEILING,
                            ]);
                    }

                    return $calls;
                });

                $httpMs += (hrtime(true) - $poolStart) / 1e6;

                foreach ($chunk as $i => $filter) {
                    $requests++;
                    $levelRequests++;
                    $response = $responses[(string) $i] ?? null;

                    if ($response instanceof Throwable
                        || ! $response instanceof Response
                        || $response->failed()) {
                        $unreachable++;

                        continue;
                    }

                    $rows = $response->json('response.servers') ?? [];
                    $returned = count($rows);

                    $this->emit($rows, $seen, $onServer, $only, $skipped);
                    unset($rows);

                    if ($returned >= $this->saturatedAt()) {
                        if ($isLastAxis) {
                            $truncated++;
                        } else {
                            $saturated[] = $filter;
                        }
                    }
                }
            }

            if ($onLevel !== null) {
                $onLevel($levelIndex, $levelRequests, (microtime(true) - $levelStart) * 1000);
            }

            $levelIndex++;
        }

        return new SweepResult(count($seen), $requests, $truncated, $unreachable, $skipped, $httpMs);
    }

    /**
     * Addressed first, built second — see SteamServerSweep::collect, which this
     * is the level-parallel twin of.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, true>  $seen
     * @param  callable(DiscoveredServer): void  $onServer
     * @param  array<string, mixed>|null  $only
     */
    private function emit(array $rows, array &$seen, callable $onServer, ?array $only, int &$skipped): void
    {
        foreach ($rows as $row) {
            $parsed = DiscoveredServer::addressOf($row);

            if ($parsed === null) {
                continue;
            }

            [$ip, $queryPort, $gamePort] = $parsed;
            $address = $ip.':'.$queryPort;

            if (isset($seen[$address])) {
                continue;
            }

            $seen[$address] = true;

            if ($only !== null && ! isset($only[$ip.':'.$gamePort])) {
                $skipped++;

                continue;
            }

            $server = DiscoveredServer::fromApi($row);

            if ($server === null) {
                continue;
            }

            $onServer($server);
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function request(string $key, string $filter, array $keys, float &$httpMs): array
    {
        $started = hrtime(true);

        try {
            $response = Http::timeout(90)->retry(3, 1000, throw: false)->get(self::ENDPOINT, [
                'key' => $key,
                'filter' => $filter,
                'limit' => SteamServerSweep::CEILING,
            ]);
        } catch (ConnectionException $exception) {
            $httpMs += (hrtime(true) - $started) / 1e6;

            throw new RuntimeException(
                'Steam API unreachable for filter '.$filter.': '.$this->redact($exception->getMessage(), $keys),
            );
        }

        $httpMs += (hrtime(true) - $started) / 1e6;

        if ($response->failed()) {
            throw new RuntimeException("Steam API returned HTTP {$response->status()} for filter {$filter}");
        }

        return $response->json('response.servers') ?? [];
    }

    /**
     * @param  list<string>  $keys
     */
    private function redact(string $message, array $keys): string
    {
        foreach ($keys as $key) {
            if ($key !== '') {
                $message = str_replace($key, '[key]', $message);
            }
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = (array) config('services.steam.keys', []);

        if ($keys === []) {
            $single = (string) config('services.steam.key');
            $keys = $single === '' ? [] : [$single];
        }

        if ($keys === []) {
            throw new RuntimeException('STEAM_API_KEY is not set');
        }

        return array_values($keys);
    }
}
