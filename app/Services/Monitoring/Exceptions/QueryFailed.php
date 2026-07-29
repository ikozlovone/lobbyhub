<?php

namespace App\Services\Monitoring\Exceptions;

use App\Enums\QueryFailure;
use RuntimeException;

/**
 * The server did not give us a usable answer. Expected and routine —
 * half the catalog is offline at any moment.
 *
 * The `reason` is carried as a value rather than left in the message: the
 * submission form has to tell one kind of silence from another to say anything
 * useful, and parsing our own sentences back out would be a poor way to do it.
 */
class QueryFailed extends RuntimeException
{
    private function __construct(public readonly QueryFailure $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unresolvable(string $host): self
    {
        return new self(QueryFailure::Unresolvable, "Could not resolve host [{$host}]");
    }

    public static function unreachable(string $address, string $reason): self
    {
        return new self(QueryFailure::Unreachable, "Could not connect to [{$address}]: {$reason}");
    }

    public static function timedOut(string $address): self
    {
        return new self(QueryFailure::Silent, "Timed out reading from [{$address}]");
    }

    public static function malformed(string $reason): self
    {
        return new self(QueryFailure::Malformed, "Malformed response: {$reason}");
    }
}
