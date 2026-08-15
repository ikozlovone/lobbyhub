<?php

namespace App\Services\Discovery;

use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every server Steam knows about for one game, not the first ten thousand.
 *
 * `GetServerList` answers at most 10 000 rows and has no offset — `limit=20000`
 * and `limit=50000` both come back with 10 000, measured. So the only way past
 * it is to ask several narrower questions whose answers add up to the whole
 * population, and the interesting part is which questions those are.
 *
 * Three axes, each verified against the live API rather than taken from the
 * documentation:
 *
 *  - **region**, nine values. Complete but *not* disjoint: summing the nine
 *    counts for Garry's Mod gave 6 522 against 5 450 unfiltered, which reads
 *    like the partition is broken. Collected and deduplicated by address it is
 *    5 457 either way, differing by four servers that came and went between
 *    calls. Servers appear under more than one region; nothing is lost.
 *  - **empty / noplayers**, complete and disjoint: 6 + 171 = 177 on a bucket of
 *    177.
 *  - **name**, a substring match and its negation. `\name_match\a*` and
 *    `\nor\1\name_match\a*` came back 3 and 174 against the same 177. Any
 *    pattern splits a bucket in two with nothing outside, so this axis can be
 *    applied over and over — which is what makes the sweep bounded rather than
 *    hopeful.
 *
 * They are tried in that order, and only where they are needed: a bucket that
 * comes back under the ceiling is complete and is not subdivided. Most games
 * are one request. Counter-Strike 2, which has around thirty thousand servers,
 * costs a few dozen.
 */
class SteamServerSweep
{
    private const ENDPOINT = 'https://api.steampowered.com/IGameServersService/GetServerList/v1/';

    /** What one response is capped at, whatever `limit` asks for. */
    public const CEILING = 10_000;

    /**
     * Where a response stops being believable as a complete answer.
     *
     * The cap is not exact. Asked the same saturated question four times the
     * API answered 10 000, 9 999, 9 996 and 9 971 — so testing for the ceiling
     * itself reads a truncated bucket as a finished one, which is how the first
     * run of this swept Counter-Strike in a single request and reported ten
     * thousand servers as the whole population.
     *
     * Ninety percent, and deliberately generous. Subdividing a bucket that was
     * not actually full costs a few requests and returns the same servers,
     * which the address keying folds away. Failing to subdivide one that was
     * loses them with nothing to show for it.
     *
     * Configurable for two reasons: Steam can move the cap, and a test that has
     * to build nine thousand rows to prove the recursion works takes minutes.
     */
    private function saturatedAt(): int
    {
        return (int) config('monitoring.steam_saturated_at', 9_000);
    }

    /**
     * Servers with at least one player on them.
     *
     * The whole reason there are two sweeps. A game's population is mostly
     * empty servers — of Counter-Strike 2's hundred thousand, the non-empty
     * ones in Europe were 3 824 against more than ten thousand empty — and an
     * empty server sits on the hour-long tier anyway. Reading only the occupied
     * ones is a request or two per game instead of eighteen to sixty-eight, so
     * it can run at the cadence the busy servers actually deserve while the
     * full population is read on the cadence the quiet ones do.
     */
    public const POPULATED = '\empty\1';

    /**
     * The ladder. Each entry is a set of filter fragments that together cover
     * the bucket they are applied to.
     *
     * The letters are ordered by how evenly they cut a list of server names —
     * common ones first, so the tree stays shallow. Eight of them give 256
     * leaves under every region-and-emptiness bucket, which is far past what
     * any game on Steam needs today.
     *
     * @var list<list<string>>
     */
    public const AXES = [
        ['\region\0', '\region\1', '\region\2', '\region\3', '\region\4',
            '\region\5', '\region\6', '\region\7', '\region\255'],
        ['\empty\1', '\noplayers\1'],
        ...self::NAME_AXES,
    ];

    /** @var list<list<string>> */
    private const NAME_AXES = [
        ['\name_match\*e*', '\nor\1\name_match\*e*'],
        ['\name_match\*a*', '\nor\1\name_match\*a*'],
        ['\name_match\*o*', '\nor\1\name_match\*o*'],
        ['\name_match\*s*', '\nor\1\name_match\*s*'],
        ['\name_match\*r*', '\nor\1\name_match\*r*'],
        ['\name_match\*n*', '\nor\1\name_match\*n*'],
        ['\name_match\*1*', '\nor\1\name_match\*1*'],
        ['\name_match\*t*', '\nor\1\name_match\*t*'],
    ];

    /**
     * Hands each server to the caller as it arrives, rather than returning them.
     *
     * Holding a game's population in memory was the first shape and it does not
     * survive Counter-Strike: thirty thousand objects plus the arrays built
     * from them exhausted a 128 MB process without printing anything, which is
     * what a fatal memory error looks like from the outside. What is kept now
     * is one address per server — the set that makes the overlapping axes fold
     * into one row each — and nothing else grows with the size of the game.
     *
     * @param  callable(DiscoveredServer): void  $onServer
     * @param  bool  $populatedOnly  Only servers with someone on them
     * @param  array<string, mixed>|null  $only  Addresses (`ip:gameport`) worth
     *                                           building. Null takes everything; a set drops the rest before a
     *                                           DiscoveredServer is made of it, which on a catalog that only
     *                                           refreshes what it holds is ninety-nine rows in a hundred.
     */
    public function stream(Game $game, callable $onServer, bool $populatedOnly = false, ?array $only = null): SweepResult
    {
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

        $this->collect(
            $keys,
            '\appid\\'.$game->steam_appid.($populatedOnly ? self::POPULATED : ''),
            /*
             * The emptiness axis is dropped when the question already carries
             * it. Applied on top of `\empty\1` its two children are "occupied
             * and empty", which is nothing, and "occupied and occupied", which
             * is the parent again — a wasted request that subdivides nothing.
             */
            $populatedOnly ? self::populatedAxes() : self::AXES,
            $seen,
            $onServer,
            $requests,
            $truncated,
            $unreachable,
            $skipped,
            $httpMs,
            $only,
        );

        return new SweepResult(count($seen), $requests, $truncated, $unreachable, $skipped, $httpMs);
    }

    /**
     * @return list<list<string>>
     */
    public static function populatedAxes(): array
    {
        return [self::AXES[0], ...self::NAME_AXES];
    }

    /**
     * The keys to deal requests round-robin across.
     *
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = (array) config('services.steam.keys', []);

        // Falls back to the single-value setting, so a deployment that has not
        // heard of the plural one keeps working.
        if ($keys === []) {
            $single = (string) config('services.steam.key');
            $keys = $single === '' ? [] : [$single];
        }

        if ($keys === []) {
            throw new RuntimeException('STEAM_API_KEY is not set');
        }

        return array_values($keys);
    }

    /**
     * One bucket, and its children if it turned out to be full.
     *
     * The address set is what makes the overlapping axes safe: a server listed
     * under two regions is handed to the caller once, the first time it is met.
     *
     * @param  list<string>  $keys
     * @param  list<list<string>>  $axes
     * @param  array<string, true>  $seen
     * @param  callable(DiscoveredServer): void  $onServer
     * @param  array<string, mixed>|null  $only
     */
    private function collect(
        array $keys,
        string $filter,
        array $axes,
        array &$seen,
        callable $onServer,
        int &$requests,
        int &$truncated,
        int &$unreachable,
        int &$skipped,
        float &$httpMs,
        ?array $only,
    ): void {
        /*
         * One bucket failing is not the game failing.
         *
         * A DNS resolve that timed out four levels into Counter-Strike aborted
         * the whole sweep and lost every bucket still unvisited — a transient
         * network blip costing a hundred thousand servers their reading. A
         * deeper bucket is counted and skipped instead, and the rest of the
         * tree carries on.
         *
         * The first request is different. If the top of the tree cannot be
         * fetched there is nothing to salvage, and no reason to make a hundred
         * further attempts to prove it, so that one is left to throw.
         */
        try {
            // Round-robin, so a game costing sixty-eight requests spreads them
            // rather than spending one key's whole allowance on itself.
            $rows = $this->request($keys[$requests % count($keys)], $filter, $keys, $httpMs);
        } catch (RuntimeException $exception) {
            if ($requests === 0) {
                throw $exception;
            }

            $unreachable++;
            $requests++;

            return;
        }

        $requests++;
        $returned = count($rows);

        /*
         * Addressed first, built second — and only for the rows that survive.
         *
         * Every row used to become a DiscoveredServer before anyone asked
         * whether it was wanted: a tag-string regex, two mb_substr and an
         * allocation each, a hundred thousand times for Counter-Strike, to keep
         * a few hundred. `addressOf` answers the same question with three string
         * operations, so the rows nobody asked for cost nothing but the decode
         * that already happened.
         *
         * Deduplication stays above the filter. `found` is what Steam listed,
         * which is a different number from what the catalog took, and both are
         * worth printing — one says the sweep is complete, the other says the
         * catalog is frozen.
         */
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

        /*
         * Dropped before descending, and that is not tidiness.
         *
         * A saturated bucket is ten thousand decoded rows — tens of megabytes —
         * and this frame holds them for as long as its children are running.
         * Five levels deep that is the same payload five times over, which is a
         * worker dying of memory with nothing printed: the shape of a PHP fatal
         * seen from outside. Only the count matters from here.
         */
        unset($rows);

        // Well short of the cap means Steam gave us everything it had for this
        // question, so there is nothing under it to ask.
        if ($returned < $this->saturatedAt()) {
            return;
        }

        if ($axes === []) {
            // Out of ways to narrow. Counted rather than swallowed: a sweep that
            // silently returns a truncated catalog is the failure this class
            // exists to prevent, and the caller says so in its output.
            $truncated++;

            return;
        }

        $next = $axes;
        $axis = array_shift($next);

        foreach ($axis as $fragment) {
            $this->collect(
                $keys, $filter.$fragment, $next, $seen, $onServer,
                $requests, $truncated, $unreachable, $skipped, $httpMs, $only,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function request(string $key, string $filter, array $keys, float &$httpMs): array
    {
        // Around the transfer and nothing else. The decode below is real work
        // too, but it is work this machine does and can be made cheaper; the
        // wait above it is Steam's and cannot.
        $started = hrtime(true);

        try {
            $response = Http::timeout(90)->retry(3, 1000, throw: false)->get(self::ENDPOINT, [
                'key' => $key,
                'filter' => $filter,
                'limit' => self::CEILING,
            ]);
        } catch (ConnectionException $exception) {
            $httpMs += (hrtime(true) - $started) / 1e6;
            /*
             * Redacted, and this is not caution for its own sake.
             *
             * A cURL failure names the URL it was trying, and the key is a query
             * parameter on it — so this message went into the console, into
             * laravel.log and into failed_jobs, in full, every time DNS was slow.
             * The reason is worth keeping (a resolve timeout and an HTTP 403 need
             * different fixes); the credential is not.
             */
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
     * Every key this process knows, blanked out of a string.
     *
     * Blunt on purpose: it does not try to understand where in the text a key
     * might appear, only that none of them leaves in one. A transport error can
     * quote the URL, the headers, or a redirect target, and guessing which is
     * how a redaction misses.
     *
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
}
