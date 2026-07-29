<?php

namespace App\Services\Catalog\Exceptions;

use App\Models\Server;
use RuntimeException;

/**
 * The submitted address is already in the catalog.
 *
 * Not an error the submitter caused — most of these are owners finding their
 * own server after discovery imported it — so the response carries the listing
 * they were looking for instead of just a refusal.
 */
class ServerAlreadyListed extends RuntimeException
{
    public function __construct(public readonly Server $server)
    {
        parent::__construct("Server [{$server->slug}] is already listed");
    }
}
