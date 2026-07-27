<?php

namespace App\Services\Monitoring;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Reorders a batch so we never hammer one provider.
 *
 * The due-servers query is ordered by staleness, so servers imported together
 * — typically a whole rack behind one IP — come out adjacent. Interleaving by
 * host spreads them out, and a per-host cap keeps a single provider from
 * filling the batch at all.
 */
class HostSpread
{
    /** Servers held back by the per-host cap on the last arrange() call. */
    private int $heldBack = 0;

    /**
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, Server>
     */
    public function arrange(Collection $servers, ?int $maxPerHost = null): Collection
    {
        $maxPerHost = $maxPerHost ?? (int) config('monitoring.max_per_host');

        $groups = $servers
            ->groupBy(fn (Server $server) => $server->ip_address ?: $server->host)
            ->map(fn (Collection $group) => $group->values());

        $this->heldBack = $maxPerHost > 0
            ? $groups->sum(fn (Collection $group) => max(0, $group->count() - $maxPerHost))
            : 0;

        if ($maxPerHost > 0) {
            $groups = $groups->map(fn (Collection $group) => $group->take($maxPerHost));
        }

        // Round-robin: one server from each host, then the next from each, …
        $arranged = collect();

        for ($position = 0; ; $position++) {
            $added = false;

            foreach ($groups as $group) {
                if ($group->has($position)) {
                    $arranged->push($group[$position]);
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $arranged;
    }

    public function heldBack(): int
    {
        return $this->heldBack;
    }
}
