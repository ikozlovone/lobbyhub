<?php

namespace App\Services\Monitoring\Exceptions;

use App\Enums\QueryProtocol;
use RuntimeException;

/**
 * A configuration problem, not a server problem — unlike QueryFailed this
 * must not be recorded as downtime.
 */
class UnsupportedProtocol extends RuntimeException
{
    public static function for(QueryProtocol $protocol): self
    {
        return new self("No monitoring driver for protocol [{$protocol->value}]");
    }
}
