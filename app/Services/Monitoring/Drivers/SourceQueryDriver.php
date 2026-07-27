<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Server;
use App\Services\Monitoring\Contracts\ProvidesServerDetails;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\Support\ByteReader;
use App\Services\Monitoring\Support\ResolvesHost;
use App\Services\Monitoring\Support\RulesPayload;
use App\Services\Monitoring\Support\SourceTags;
use Illuminate\Support\Facades\Cache;

/**
 * Valve A2S_INFO over UDP — Rust, ARK, CS2, Garry's Mod, 7 Days to Die.
 *
 * Since late 2020 servers answer the first request with a challenge that has to
 * be echoed back, so a full query is two round trips.
 */
class SourceQueryDriver implements ProvidesServerDetails, ServerQueryDriver
{
    use ResolvesHost;

    private const HEADER_SIMPLE = "\xFF\xFF\xFF\xFF";

    private const HEADER_MULTI = "\xFF\xFF\xFF\xFE";

    private const A2S_INFO = 'T';

    private const A2S_RULES = 'V';

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
        $tags = SourceTags::parse($extra['keywords']);

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
            steamId: $extra['steam_id'],
            wipedAt: $tags['wiped_at'],
            playersQueued: $tags['queued'],
        );
    }

    /**
     * A2S_RULES: everything the server publishes about itself beyond its status.
     *
     * A second, heavier exchange than A2S_INFO — a Rust server answers with
     * dozens of rules — so it runs on its own slow cadence, not every poll.
     *
     * @return array<string, string>
     */
    public function details(Server $server): array
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

            $datagram = $this->rulesExchange($socket, $address, "\xFF\xFF\xFF\xFF");

            if ($this->isChallenge($datagram)) {
                $datagram = $this->rulesExchange($socket, $address, $this->challengeFrom($datagram));
            }

            $payload = RulesPayload::collect(
                $datagram,
                fn () => (string) @fread($socket, self::MAX_DATAGRAM),
            );
        } finally {
            fclose($socket);
        }

        return $this->normalizeRules(RulesPayload::parse($payload));
    }

    /**
     * @param  resource  $socket
     */
    private function rulesExchange($socket, string $address, string $challenge): string
    {
        if (@fwrite($socket, self::HEADER_SIMPLE.self::A2S_RULES.$challenge) === false) {
            throw QueryFailed::unreachable($address, 'write failed');
        }

        $datagram = (string) @fread($socket, self::MAX_DATAGRAM);

        if ($datagram === '') {
            if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                throw QueryFailed::timedOut($address);
            }

            throw QueryFailed::unreachable($address, 'no datagram (port closed or filtered)');
        }

        return $datagram;
    }

    /**
     * Rust splits a long description across description_00…description_15, so the
     * pieces are joined back into one value and the numbered keys dropped.
     *
     * @param  array<string, string>  $rules
     * @return array<string, string>
     */
    private function normalizeRules(array $rules): array
    {
        $chunks = [];

        foreach ($rules as $key => $value) {
            if (preg_match('/^description_(\d+)$/', $key, $matches)) {
                $chunks[(int) $matches[1]] = $value;
                unset($rules[$key]);
            }
        }

        if ($chunks !== []) {
            ksort($chunks);
            $description = trim(str_replace('\n', "\n", implode('', $chunks)));

            if ($description !== '') {
                $rules['description'] = mb_substr($description, 0, 4000);
            }
        }

        return $rules;
    }

    /**
     * @return array{keywords: ?string, game_port: ?int, steam_id: ?string}
     */
    private function readExtraData(ByteReader $reader): array
    {
        if ($reader->remaining() < 1) {
            return ['keywords' => null, 'game_port' => null, 'steam_id' => null];
        }

        $flags = $reader->byte();
        $keywords = null;
        $gamePort = null;
        $steamId = null;

        if ($flags & 0x80) {
            // The port players connect to, which often differs from the one we query.
            $gamePort = $reader->short();
        }

        if ($flags & 0x10) {
            // 64-bit little-endian; kept as a string because it overflows a
            // JavaScript number and PHP int semantics vary by platform.
            $parts = unpack('Vlow/Vhigh', $reader->raw(8));
            $steamId = (string) ($parts['high'] * 4294967296 + $parts['low']);
        }

        if ($flags & 0x40) {
            $reader->short();           // spectator port
            $reader->string();          // spectator name
        }

        if ($flags & 0x20) {
            $keywords = $reader->string();
        }

        return ['keywords' => $keywords, 'game_port' => $gamePort, 'steam_id' => $steamId];
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
