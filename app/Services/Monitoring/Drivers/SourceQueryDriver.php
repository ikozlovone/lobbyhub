<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\Support\ByteReader;
use App\Services\Monitoring\Support\ResolvesHost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Valve A2S_INFO over UDP — Rust, ARK, CS2, Garry's Mod, 7 Days to Die.
 *
 * Since late 2020 servers answer the first request with a challenge that has to
 * be echoed back, so a full query is two round trips.
 */
class SourceQueryDriver implements ServerQueryDriver
{
    use ResolvesHost;

    private const HEADER_SIMPLE = "\xFF\xFF\xFF\xFF";

    private const HEADER_MULTI = "\xFF\xFF\xFF\xFE";

    private const A2S_INFO = 'T';

    private const RESPONSE_INFO = 'I';

    private const RESPONSE_CHALLENGE = 'A';

    private const PAYLOAD = "Source Engine Query\x00";

    /** A2S_INFO answers fit in one datagram; this is just a ceiling. */
    private const MAX_DATAGRAM = 8192;

    /** The Ship reports three extra fields the rest of the family doesn't. */
    private const APPID_THE_SHIP = 2400;

    public function query(Server $server): QueryResult
    {
        $ip = $this->resolveIp($server->host);
        $port = $server->queryPort();
        $address = "{$ip}:{$port}";

        $timeout = (float) config('monitoring.timeout');
        $socket = @stream_socket_client("udp://{$address}", $errno, $errstr, $timeout);

        if ($socket === false) {
            throw QueryFailed::unreachable($address, $errstr !== '' ? $errstr : "errno {$errno}");
        }

        try {
            stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));

            // Challenges survive across sockets and minutes (verified against a
            // live server), so a cached one usually saves a whole round trip.
            // A stale one costs nothing: the server just answers with a new one.
            [$datagram, $latencyMs] = $this->exchange($socket, $address, $this->cachedChallenge($address));
            $attempts = (int) config('monitoring.source.challenge_retries', 2);

            for ($i = 0; $i < $attempts && $this->isChallenge($datagram); $i++) {
                $challenge = $this->challengeFrom($datagram);
                $this->rememberChallenge($address, $challenge);

                // Latency is the final exchange only, so it means one round trip
                // in every game rather than "however many the protocol needed".
                [$datagram, $latencyMs] = $this->exchange($socket, $address, $challenge);
            }
        } finally {
            fclose($socket);
        }

        return $this->parseInfo($datagram, $latencyMs, $ip);
    }

    /**
     * Public so the wire format can be tested without a socket.
     */
    public function parseInfo(string $datagram, ?int $latencyMs = null, ?string $ip = null): QueryResult
    {
        $reader = new ByteReader($datagram);
        $header = $reader->raw(4);

        if ($header === self::HEADER_MULTI) {
            throw QueryFailed::malformed('split A2S_INFO response');
        }

        if ($header !== self::HEADER_SIMPLE) {
            throw QueryFailed::malformed('unexpected packet header');
        }

        $type = chr($reader->byte());

        if ($type !== self::RESPONSE_INFO) {
            throw QueryFailed::malformed("unexpected response type [{$type}]");
        }

        $reader->byte();                // protocol version
        $name = $reader->string();      // live server title
        $map = $reader->string();
        $reader->string();              // game folder
        $reader->string();              // game name
        $appId = $reader->short();
        $players = $reader->byte();
        $maxPlayers = $reader->byte();
        $reader->byte();                // bots
        $reader->byte();                // server type
        $reader->byte();                // environment
        $reader->byte();                // visibility
        $reader->byte();                // VAC

        if ($appId === self::APPID_THE_SHIP) {
            $reader->skip(3);           // mode, witnesses, duration
        }

        $version = $reader->string();
        $extra = $this->readExtraData($reader);
        $tags = $this->parseTags($extra['keywords']);

        return new QueryResult(
            playersOnline: $tags['players'] ?? $players,
            playersMax: $tags['max_players'] ?? $maxPlayers,
            version: $version === '' ? null : mb_substr($version, 0, 255),
            map: $map === '' ? null : mb_substr($map, 0, 255),
            // A2S has no MOTD; the server title is its live equivalent, and it
            // must not overwrite the catalog `name` the submitter chose.
            motd: $name === '' ? null : mb_substr($name, 0, 512),
            latencyMs: $latencyMs === null ? null : min($latencyMs, 65535),
            ipAddress: $ip,
            gamePort: $extra['game_port'],
            wipedAt: $tags['wiped_at'],
            playersQueued: $tags['queued'],
        );
    }

    /**
     * @return array{keywords: ?string, game_port: ?int}
     */
    private function readExtraData(ByteReader $reader): array
    {
        if ($reader->remaining() < 1) {
            return ['keywords' => null, 'game_port' => null];
        }

        $flags = $reader->byte();
        $keywords = null;
        $gamePort = null;

        if ($flags & 0x80) {
            // The port players connect to, which often differs from the one we query.
            $gamePort = $reader->short();
        }

        if ($flags & 0x10) {
            $reader->skip(8);           // server steam id
        }

        if ($flags & 0x40) {
            $reader->short();           // spectator port
            $reader->string();          // spectator name
        }

        if ($flags & 0x20) {
            $keywords = $reader->string();
        }

        return ['keywords' => $keywords, 'game_port' => $gamePort];
    }

    /**
     * Rust publishes real values in the tag list, which is the only place some of
     * them exist at all:
     *
     *   cp/mp — current and max players. A2S gives each a single byte, so a
     *           300-slot server literally cannot describe itself there.
     *   qp    — players waiting in the join queue.
     *   born  — unix time the server last started, in practice the last wipe.
     *
     * Other A2S games send different tags; the patterns below are narrow enough
     * not to match them, so everything stays null and the byte fields win.
     *
     * @return array{players: ?int, max_players: ?int, queued: ?int, wiped_at: ?Carbon}
     */
    private function parseTags(?string $keywords): array
    {
        $parsed = ['players' => null, 'max_players' => null, 'queued' => null, 'wiped_at' => null];

        if ($keywords === null) {
            return $parsed;
        }

        foreach (explode(',', $keywords) as $tag) {
            match (true) {
                (bool) preg_match('/^cp(\d+)$/', $tag, $m) => $parsed['players'] = (int) $m[1],
                (bool) preg_match('/^mp(\d+)$/', $tag, $m) => $parsed['max_players'] = (int) $m[1],
                (bool) preg_match('/^qp(\d+)$/', $tag, $m) => $parsed['queued'] = min((int) $m[1], 65535),
                (bool) preg_match('/^born(\d+)$/', $tag, $m) => $parsed['wiped_at'] = $this->timestamp((int) $m[1]),
                default => null,
            };
        }

        return $parsed;
    }

    /** Reject nonsense timestamps rather than storing them. */
    private function timestamp(int $unix): ?Carbon
    {
        if ($unix < 1_400_000_000 || $unix > now()->addDay()->timestamp) {
            return null;
        }

        return Carbon::createFromTimestamp($unix);
    }

    /**
     * The challenge is four raw bytes, so it is stored hex-encoded: text-backed
     * cache stores (database, Redis) reject invalid UTF-8.
     *
     * Cache failures are swallowed on purpose — this is an optimization, and a
     * broken cache must never take monitoring down with it.
     */
    private function cachedChallenge(string $address): string
    {
        try {
            $hex = Cache::get($this->challengeKey($address));

            return is_string($hex) && strlen($hex) === 8 ? (hex2bin($hex) ?: '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function rememberChallenge(string $address, string $challenge): void
    {
        try {
            Cache::put(
                $this->challengeKey($address),
                bin2hex($challenge),
                (int) config('monitoring.source.challenge_ttl', 3600),
            );
        } catch (\Throwable) {
            // Next query just pays the extra round trip.
        }
    }

    private function challengeKey(string $address): string
    {
        return "a2s:challenge:{$address}";
    }

    /**
     * One request/response round trip.
     *
     * @param  resource  $socket
     * @return array{0: string, 1: int} the datagram and how long it took, in ms
     */
    private function exchange($socket, string $address, string $challenge): array
    {
        $request = self::HEADER_SIMPLE.self::A2S_INFO.self::PAYLOAD.$challenge;

        $startedAt = microtime(true);

        if (@fwrite($socket, $request) === false) {
            throw QueryFailed::unreachable($address, 'write failed');
        }

        $datagram = @fread($socket, self::MAX_DATAGRAM);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($datagram === false || $datagram === '') {
            if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                throw QueryFailed::timedOut($address);
            }

            // On UDP an empty read means the OS saw ICMP port-unreachable —
            // the port is closed or filtered, not the payload malformed.
            throw QueryFailed::unreachable($address, 'no datagram (port closed or filtered)');
        }

        return [$datagram, $elapsedMs];
    }

    private function isChallenge(string $datagram): bool
    {
        return strlen($datagram) >= 9
            && str_starts_with($datagram, self::HEADER_SIMPLE.self::RESPONSE_CHALLENGE);
    }

    private function challengeFrom(string $datagram): string
    {
        return substr($datagram, 5, 4);
    }
}
