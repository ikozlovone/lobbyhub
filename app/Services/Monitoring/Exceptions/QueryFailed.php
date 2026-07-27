<?php

namespace App\Services\Monitoring\Exceptions;

use RuntimeException;

/**
 * The server did not give us a usable answer. Expected and routine —
 * half the catalog is offline at any moment.
 */
class QueryFailed extends RuntimeException
{
    public static function unresolvable(string $host): self
    {
        return new self("Could not resolve host [{$host}]");
    }

    public static function unreachable(string $address, string $reason): self
    {
        return new self("Could not connect to [{$address}]: {$reason}");
    }

    public static function timedOut(string $address): self
    {
        return new self("Timed out reading from [{$address}]");
    }

    public static function malformed(string $reason): self
    {
        return new self("Malformed response: {$reason}");
    }
}
