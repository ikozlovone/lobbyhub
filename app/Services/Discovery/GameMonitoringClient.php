<?php

namespace App\Services\Discovery;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads gamemonitoring.net's public server list.
 *
 * One endpoint, three parameters: `game` is a Steam appid — the same number
 * `games.steam_appid` holds — and `limit`/`offset` page through it. The answer
 * is `{"response":{"items":[…]}}`, one object per server, of which this app
 * wants three fields: `ip`, `port` and `query`.
 *
 * Everything else in that payload is theirs. Names, player counts, maps and
 * countries are a copy of a measurement somebody else took, and the catalog
 * has its own monitor for those — see GameMonitoringSync for why an imported
 * row carries nothing but an address.
 *
 * Paging stops on a short page, which is the only end-of-list signal the API
 * gives: there is no total in the envelope.
 */
class GameMonitoringClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $pageSize,
        private readonly int $timeout,
        private readonly int $pauseMs,
        private readonly int $attempts = 4,
    ) {}

    /**
     * Every page of one game's list, in order.
     *
     * A generator so a sweep of forty thousand servers is never all in memory
     * at once, and so the caller can stop early — `--pages` on the command —
     * without the pages it did not want having been fetched.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function pages(int $appId, ?int $maxPages = null): Generator
    {
        for ($page = 0; $maxPages === null || $page < $maxPages; $page++) {
            $items = $this->page($appId, $page * $this->pageSize);

            if ($items === []) {
                return;
            }

            yield $items;

            // A page shorter than asked for is the last one.
            if (count($items) < $this->pageSize) {
                return;
            }

            // Somebody else's API, read in a loop that can run to hundreds of
            // requests. The pause is what keeps a catalog-wide import from
            // looking like something worth blocking.
            if ($this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function page(int $appId, int $offset): array
    {
        $response = Http::acceptJson()
            ->timeout($this->timeout)
            // Named separately from the read timeout because it is a different
            // failure: a box whose resolver is slow spends this whole budget
            // before a byte is asked for, and `cURL error 28: Resolving timed
            // out` is what a walk of twenty pages dies of.
            ->connectTimeout(min($this->timeout, 15))
            // Backing off rather than hammering — a second, then two, then
            // three. A list this long is read over minutes, and whatever was
            // briefly wrong with the network in the middle of it is usually
            // over by the third ask.
            ->retry($this->attempts, fn (int $attempt) => $attempt * 1000, throw: false)
            ->get($this->url.'/servers', [
                'game' => $appId,
                'limit' => $this->pageSize,
                'offset' => $offset,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "gamemonitoring returned {$response->status()} for game {$appId} at offset {$offset}",
            );
        }

        $items = $response->json('response.items');

        return is_array($items) ? array_values($items) : [];
    }
}
