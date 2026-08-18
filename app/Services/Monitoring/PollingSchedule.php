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
    /**
     * Seconds until the next query of a server that just answered.
     *
     * Takes the two inputs directly — player count and whether the server is
     * a promoted one — rather than a Server or ServerState model. Player
     * count lives in `server_states` now, and the sole caller
     * (QueryServer::recordOnline) has the just-measured value in hand;
     * loading state to read it back would be a needless round trip.
     */
    public function intervalFor(int $playersOnline, bool $isPromoted): int
    {
        if ($isPromoted) {
            return (int) config('monitoring.promoted_interval');
        }

        foreach (config('monitoring.tiers', []) as $tier) {
            if ($playersOnline >= $tier['min_players']) {
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

    /** Queries per hour a server on this tier is expected to receive. */
    public function expectedHourlyQueries(int $playersOnline, bool $isPromoted): float
    {
        return 3600 / max(1, $this->intervalFor($playersOnline, $isPromoted));
    }

    /*
     * The same figure for every active server at once, done in SQL.
     *
     * The naive form — chunk through servers, ask intervalFor() for each — is
     * one round trip per thousand rows and the same number of PHP iterations,
     * and at 23k servers ran for 13 seconds every time the admin page was
     * opened. The tiers are just thresholds, so the whole thing folds into a
     * single CASE. The branch order matches intervalFor(): first match wins in
     * PHP, and Postgres evaluates CASE branches top-down the same way, so a
     * promoted server hits the promoted branch even if its player count would
     * qualify for a slower tier.
     */
    public function expectedHourlyQueriesForActive(): float
    {
        $promoted = max(1, (int) config('monitoring.promoted_interval'));
        $default = max(1, (int) config('monitoring.interval'));

        $cases = collect(config('monitoring.tiers', []))
            ->map(fn (array $tier) => sprintf(
                'when server_states.players_online >= %d then 3600.0 / %d',
                (int) $tier['min_players'],
                max(1, (int) $tier['interval']),
            ))
            // Bound rather than SQL's own now(): the branch is prepended, so its
            // placeholder is the first in the finished string. sqlite has no
            // now() and the admin page 500'd on it under test.
            ->prepend(sprintf(
                'when servers.promoted_until is not null and servers.promoted_until > ? then 3600.0 / %d',
                $promoted,
            ))
            ->implode(' ');

        return (float) Server::query()->active()
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->selectRaw("coalesce(sum(case {$cases} else 3600.0 / {$default} end), 0) as expected", [now()])
            ->value('expected');
    }
}
