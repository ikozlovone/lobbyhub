<?php

namespace App\Services\Monitoring\Drivers;

use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\Support\ResolvesHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * FiveM / CitizenFX — plain HTTP, no binary protocol at all.
 *
 * A server exposes three endpoints on its game port:
 *   /dynamic.json — clients, maxclients, hostname, mapname, gametype
 *   /info.json    — server build, resources, sv_* variables
 *   /players.json — the full player list
 *
 * We ask for `dynamic.json` only. It carries everything the catalog shows, and
 * one request per poll keeps FiveM as cheap as the other two games — the load
 * model is built on worker-seconds, and each extra endpoint is another one.
 */
class FiveMQueryDriver implements ServerQueryDriver
{
    use ResolvesHost;

    public function query(Server $server): QueryResult
    {
        // Resolved up front for the geo lookup, exactly as the other drivers do.
        $ip = $this->resolveIp($server->host);
        $port = $server->queryPort();
        $address = "{$server->host}:{$port}";

        $timeout = (float) config('monitoring.timeout');
        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("http://{$address}/dynamic.json");
        } catch (ConnectionException $exception) {
            throw QueryFailed::unreachable($address, $exception->getMessage());
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        // A server with sv_endpointPrivacy on answers 403; a dead one answers 5xx.
        if ($response->failed()) {
            throw QueryFailed::unreachable($address, "HTTP {$response->status()}");
        }

        return $this->parseDynamic($response->body(), $latencyMs, $ip);
    }

    /**
     * Public so the payload shape can be tested without a network call.
     */
    public function parseDynamic(string $body, ?int $latencyMs = null, ?string $ip = null): QueryResult
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw QueryFailed::malformed('dynamic.json is not a JSON object');
        }

        // Missing counts mean this is not a FiveM endpoint — an unrelated web
        // server on the same port would otherwise be recorded as an empty server.
        $hasMax = array_key_exists('sv_maxclients', $data) || array_key_exists('maxclients', $data);

        if (! array_key_exists('clients', $data) || ! $hasMax) {
            throw QueryFailed::malformed('dynamic.json has no player counts');
        }

        $map = $this->clean($data['mapname'] ?? null);

        return new QueryResult(
            playersOnline: max(0, (int) $data['clients']),
            playersMax: max(0, (int) ($data['sv_maxclients'] ?? $data['maxclients'] ?? 0)),
            // dynamic.json carries no build number; that lives in info.json.
            version: null,
            map: $map,
            motd: $this->clean($data['hostname'] ?? null, 512),
            latencyMs: $latencyMs === null ? null : min($latencyMs, 65535),
            ipAddress: $ip,
        );
    }

    /**
     * FiveM hostnames are full of ^1..^9 colour codes and ~r~ style tags, which
     * would otherwise end up in the catalog as literal text.
     */
    private function clean(mixed $value, int $limit = 255): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/\^[0-9]/', '', $value);
        $text = preg_replace('/~[a-z]~/i', '', $text ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', $text ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
