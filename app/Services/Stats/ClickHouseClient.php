<?php

namespace App\Services\Stats;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper around ClickHouse — one method, `query()`, that returns
 * the `data` array from a JSON response. Everything the app reads out of CH
 * fits this shape.
 *
 * Parametrised queries use ClickHouse's own placeholder syntax rather than
 * PDO-style `?`: values sit in the query string as `param_<name>` and the
 * SQL body references them as `{name:Type}`. That way the type is declared
 * where the value is used and there is no risk of an interpolation bug
 * turning into SQL injection.
 *
 *   $ch->query(
 *       'SELECT * FROM t WHERE id = {id:UInt64} AND at >= {since:DateTime}',
 *       ['id' => 42, 'since' => '2026-08-26 00:00:00'],
 *   );
 *
 * A missing host is a valid mode — Laravel installs that do not have
 * ClickHouse still resolve this class; every method returns an empty
 * result set instead. The reader (ServerHistory) treats that identically
 * to "server has no history yet", which is what the frontend already
 * knows how to render.
 */
class ClickHouseClient
{
    /** Every read from CH is capped at ten seconds; we do not want a slow
     *  analytical machine holding a page render open indefinitely. */
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /** Whether the client is configured against a real server. */
    public function isConfigured(): bool
    {
        return $this->host !== null && $this->host !== '';
    }

    /**
     * Run a SELECT and return the rows as an array of associative arrays,
     * one per row. Column names come from the query.
     *
     * @param  array<string, scalar>  $params  named parameters for the SQL
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the HTTP request fails or CH returns
     *                          a non-2xx status. Callers that must keep
     *                          rendering catch this and degrade to empty.
     */
    public function query(string $sql, array $params = []): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $queryParams = [
            'database' => $this->database,
            'default_format' => 'JSON',
        ];
        foreach ($params as $name => $value) {
            $queryParams["param_{$name}"] = (string) $value;
        }

        $url = sprintf(
            'http://%s:%d/?%s',
            $this->host,
            $this->port,
            http_build_query($queryParams),
        );

        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withBody($sql, 'text/plain')
            ->post($url);

        if ($response->failed()) {
            throw new RuntimeException(
                "ClickHouse query failed [{$response->status()}]: ".trim($response->body()),
            );
        }

        return $response->json('data') ?? [];
    }
}
