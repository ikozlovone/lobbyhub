<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\Support\PacketBuffer;
use App\Services\Monitoring\Support\ResolvesHost;

/**
 * Minecraft Server List Ping (Java Edition, 1.7+): handshake with next-state 1,
 * then a status request; the server replies with a JSON document.
 *
 * Pre-1.7 servers speak the legacy 0xFE ping and are not handled here.
 */
class MinecraftQueryDriver implements ServerQueryDriver
{
    use ResolvesHost;

    private const STATUS_PACKET = 0x00;

    /** Guard against a hostile server declaring a huge payload. */
    private const MAX_PAYLOAD = 262_144;

    public function query(Server $server): QueryResult
    {
        [$host, $port] = $this->resolveEndpoint($server);
        $ip = $this->resolveIp($host);
        $address = "{$ip}:{$port}";

        $timeout = (float) config('monitoring.timeout');
        $socket = @stream_socket_client("tcp://{$address}", $errno, $errstr, $timeout);

        if ($socket === false) {
            throw QueryFailed::unreachable($address, $errstr !== '' ? $errstr : "errno {$errno}");
        }

        try {
            stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));

            $handshake = (new PacketBuffer)
                ->putVarInt((int) config('monitoring.minecraft.protocol_version'))
                ->putString($host)
                ->putUnsignedShort($port)
                ->putVarInt(1) // next state: status
                ->toPacket(self::STATUS_PACKET);

            $startedAt = microtime(true);

            if (@fwrite($socket, $handshake.(new PacketBuffer)->toPacket(self::STATUS_PACKET)) === false) {
                throw QueryFailed::unreachable($address, 'write failed');
            }

            $length = $this->readVarIntFromStream($socket, $address);

            if ($length <= 0 || $length > self::MAX_PAYLOAD) {
                throw QueryFailed::malformed("declared payload length {$length}");
            }

            $payload = $this->readBytes($socket, $length, $address);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        } finally {
            fclose($socket);
        }

        $buffer = new PacketBuffer($payload);

        if (($packetId = $buffer->readVarInt()) !== self::STATUS_PACKET) {
            throw QueryFailed::malformed("unexpected packet id {$packetId}");
        }

        return $this->parseStatus($buffer->readString(), $latencyMs, $ip);
    }

    /**
     * Public so the JSON shape can be tested without a socket.
     */
    public function parseStatus(string $json, ?int $latencyMs = null, ?string $ip = null): QueryResult
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw QueryFailed::malformed('status response is not a JSON object');
        }

        $version = $data['version']['name'] ?? null;

        return new QueryResult(
            // Servers that hide their player count report -1.
            playersOnline: max(0, (int) ($data['players']['online'] ?? 0)),
            playersMax: max(0, (int) ($data['players']['max'] ?? 0)),
            version: is_string($version) ? mb_substr($version, 0, 255) : null,
            motd: $this->flattenDescription($data['description'] ?? null),
            // latency_ms is a smallint; a very slow server would overflow it.
            latencyMs: $latencyMs === null ? null : min($latencyMs, 65535),
            ipAddress: $ip,
        );
    }

    /**
     * The MOTD is either a plain string or a chat component tree
     * ({"text": …, "extra": [ … ]}). Flatten it and drop § colour codes.
     */
    private function flattenDescription(mixed $description): ?string
    {
        $raw = $this->concatComponents($description);

        if ($raw === null) {
            return null;
        }

        $text = preg_replace('/§[0-9a-fk-or]/iu', '', $raw);
        $text = trim(preg_replace('/\s+/u', ' ', $text ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 512);
    }

    /**
     * Concatenate the component tree verbatim. Whitespace is normalized once by
     * the caller — trimming each part would eat the spaces between them.
     */
    private function concatComponents(mixed $description): ?string
    {
        return match (true) {
            is_string($description) => $description,
            is_array($description) => (is_string($description['text'] ?? null) ? $description['text'] : '')
                .collect($description['extra'] ?? [])
                    ->map(fn ($part) => $this->concatComponents($part) ?? '')
                    ->implode(''),
            default => null,
        };
    }

    /**
     * Minecraft hosts advertise their real port through _minecraft._tcp SRV
     * records; without this, domain-based servers on non-default ports fail.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveEndpoint(Server $server): array
    {
        $host = $server->host;
        $port = $server->queryPort();

        $srvApplies = config('monitoring.minecraft.resolve_srv')
            && ! filter_var($host, FILTER_VALIDATE_IP)
            && $port === $server->game->default_port;

        if (! $srvApplies) {
            return [$host, $port];
        }

        $records = @dns_get_record("_minecraft._tcp.{$host}", DNS_SRV) ?: [];
        $records = array_values(array_filter($records, fn ($r) => ! empty($r['target']) && ! empty($r['port'])));

        if ($records === []) {
            return [$host, $port];
        }

        usort($records, fn ($a, $b) => [$a['pri'] ?? 0, -($a['weight'] ?? 0)] <=> [$b['pri'] ?? 0, -($b['weight'] ?? 0)]);

        return [rtrim($records[0]['target'], '.'), (int) $records[0]['port']];
    }

    /** @param resource $socket */
    private function readVarIntFromStream($socket, string $address): int
    {
        $result = 0;

        for ($i = 0; $i < 5; $i++) {
            $byte = ord($this->readBytes($socket, 1, $address));
            $result |= ($byte & 0x7F) << (7 * $i);

            if (($byte & 0x80) === 0) {
                return $result;
            }
        }

        throw QueryFailed::malformed('length VarInt is longer than 5 bytes');
    }

    /** @param resource $socket */
    private function readBytes($socket, int $length, string $address): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                    throw QueryFailed::timedOut($address);
                }

                throw QueryFailed::malformed('connection closed mid-response');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
