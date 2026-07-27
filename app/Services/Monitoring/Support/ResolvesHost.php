<?php

namespace App\Services\Monitoring\Support;

use App\Services\Monitoring\Exceptions\QueryFailed;

trait ResolvesHost
{
    /**
     * Resolve to an IP before connecting: we need it for the geo lookup, and it
     * keeps the driver from paying DNS cost twice.
     */
    protected function resolveIp(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $ip = gethostbyname($host);

        if ($ip === $host || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw QueryFailed::unresolvable($host);
        }

        return $ip;
    }
}
