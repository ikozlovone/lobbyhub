<?php

namespace App\Services\Monitoring;

use App\Models\Server;

/**
 * Decides when a server should be queried again.
 *
 * Two separate questions live here: how often to poll a healthy server (by how
 * busy it is), and how long to wait after a failure (doubling backoff).
 */
class PollingSchedule
{
    /** Seconds until the next query of a server that just answered. */
    public function intervalFor(Server $server): int
    {
        if ($server->isPromoted()) {
            return (int) config('monitoring.promoted_interval');
        }

        foreach (config('monitoring.tiers', []) as $tier) {
            if ($server->players_online >= $tier['min_players']) {
                return (int) $tier['interval'];
            }
        }

        return (int) config('monitoring.interval');
    }

    /**
     * Seconds until the next attempt after a failure. Deliberately based on the
     * plain interval rather than the tier: a failed server reports zero players,
     * which would otherwise drop every outage into the quietest tier.
     */
    public function backoffFor(int $failures): int
    {
        $interval = (int) config('monitoring.interval');

        return (int) min($interval * 2 ** min($failures, 12), (int) config('monitoring.max_interval'));
    }

    /** Queries per hour this server is expected to receive — used by monitoring:status. */
    public function expectedHourlyQueries(Server $server): float
    {
        return 3600 / max(1, $this->intervalFor($server));
    }
}
