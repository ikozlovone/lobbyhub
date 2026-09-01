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
    /** Their cap on the game list, which is lower than the server list's. */
    private const GAMES_PAGE_SIZE = 100;

    /**
     * The address their host resolved to, looked up once and pinned for the
     * rest of the walk. Null until the first page asks for it, and false when
     * the lookup found nothing — see resolved().
     *
     * @var list<string>|null|false
     */
    private array|null|false $pinned = null;

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
     * `$startOffset` is how a walk that died on page sixteen is continued
     * rather than repeated. Repeating is safe — see GameMonitoringSync — it is
     * just sixteen requests at somebody else's API for work already done.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function pages(int $appId, ?int $maxPages = null, int $startOffset = 0): Generator
    {
        for ($page = 0; $maxPages === null || $page < $maxPages; $page++) {
            $items = $this->page($appId, $startOffset + $page * $this->pageSize);

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

    /** Rows per request, so a caller can say which offset it got to. */
    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * How this reaches their host, which is the part that kept failing.
     *
     * Two curl settings, both aimed at `cURL error 28: Resolving timed out`,
     * which killed a walk of Rust's twenty pages twice — once on page four and
     * once on page sixteen. It is not their API being slow: the ten seconds go
     * before a byte is asked for, on a box whose resolver is intermittently
     * not there.
     *
     * IPv4 only, because a machine with IPv6 configured and no working IPv6
     * path to DNS waits out the AAAA lookup on every single request, and this
     * makes one per page.
     *
     * And the answer is pinned. Each page is a fresh curl handle with a fresh
     * DNS cache, so a twenty-page walk asks the resolver twenty times for a
     * name that has not moved. Looking it up once and handing curl the address
     * for the rest turns twenty chances to fail into one — and when the lookup
     * itself fails, nothing is pinned and curl resolves as it did before.
     *
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        $options = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];

        foreach ($this->resolved() as $line) {
            $options[CURLOPT_RESOLVE][] = $line;
        }

        return ['curl' => $options];
    }

    /**
     * `host:port:address` lines for CURLOPT_RESOLVE, from one lookup.
     *
     * Every address the name has, not just the first: their host is behind a
     * CDN, and pinning one of several is how a pass survives that address
     * going away mid-walk.
     *
     * @return list<string>
     */
    private function resolved(): array
    {
        if ($this->pinned === false) {
            return [];
        }

        if ($this->pinned !== null) {
            return $this->pinned;
        }

        $parts = parse_url($this->url);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        // Nothing to pin: no host to speak of, or one that is already an
        // address.
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->pinned = false;

            return [];
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || $addresses === []) {
            $this->pinned = false;

            return [];
        }

        return $this->pinned = array_map(
            fn (string $address) => "{$host}:{$port}:{$address}",
            array_values($addresses),
        );
    }

    /**
     * Every page of their game catalogue, biggest first.
     *
     * Their own page cap is a hundred here rather than the thousand the server
     * list allows, and the envelope carries a `count` — which is not used:
     * a short page ends the walk, the same rule as everywhere else, and one
     * rule that holds is worth more than two that mostly agree.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function games(?int $maxPages = null): Generator
    {
        for ($page = 0; $maxPages === null || $page < $maxPages; $page++) {
            $items = $this->fetch('/games', [
                'limit' => self::GAMES_PAGE_SIZE,
                'sort' => 'servers',
                'offset' => $page * self::GAMES_PAGE_SIZE,
            ]);

            if ($items === []) {
                return;
            }

            yield $items;

            if (count($items) < self::GAMES_PAGE_SIZE) {
                return;
            }

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
        return $this->fetch('/servers', [
            'game' => $appId,
            'limit' => $this->pageSize,
            'offset' => $offset,
        ]);
    }

    /**
     * One request, and the items out of the envelope they wrap everything in.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function fetch(string $path, array $query): array
    {
        $response = Http::acceptJson()
            ->withOptions($this->connection())
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
            ->get($this->url.$path, $query);

        if ($response->failed()) {
            $where = http_build_query($query);

            throw new RuntimeException(
                "gamemonitoring returned {$response->status()} for {$path}?{$where}",
            );
        }

        $items = $response->json('response.items');

        return is_array($items) ? array_values($items) : [];
    }
}
