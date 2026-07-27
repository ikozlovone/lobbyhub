<?php

namespace App\Services\Monitoring\Contracts;

use App\Models\Server;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\QueryResult;

interface ServerQueryDriver
{
    /**
     * Query a live server.
     *
     * Returning normally means the server answered. An unreachable, timing-out
     * or malformed server raises QueryFailed — the caller turns that into an
     * offline sample, so drivers never report offline themselves.
     *
     * @throws QueryFailed
     */
    public function query(Server $server): QueryResult;
}
