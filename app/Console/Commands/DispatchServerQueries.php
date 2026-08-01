<?php

namespace App\Console\Commands;

use App\Jobs\QueryServer;
use App\Models\Server;
use App\Services\Monitoring\HostSpread;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DispatchServerQueries extends Command
{
    protected $signature = 'servers:query
        {--limit= : How many servers to dispatch (default: monitoring.batch_size)}
        {--server= : Query one server by slug, ignoring its schedule}
        {--sync : Run the queries inline instead of queueing them}';

    protected $description = 'Dispatch monitoring queries for servers that are due';

    public function handle(ServerQueryManager $manager, HostSpread $spread): int
    {
        $single = (bool) $this->option('server');
        $limit = (int) ($this->option('limit') ?: config('monitoring.batch_size'));

        $servers = $single
            ? Server::query()->where('slug', $this->option('server'))->get()
            : $this->dueServers($limit);

        if ($servers->isEmpty()) {
            $this->info('No servers due.');

            return self::SUCCESS;
        }

        // Games whose driver is not written yet would otherwise fail every cycle.
        [$supported, $skipped] = $servers->load('game')
            ->partition(fn (Server $server) => $manager->supports($server->game->query_protocol));

        if (! $single) {
            $supported = $spread->arrange($supported);
            $this->reportBacklog($limit, $spread->heldBack());
            $this->lease($supported);
        }

        foreach ($supported as $server) {
            $this->option('sync')
                ? QueryServer::dispatchSync($server)
                : QueryServer::dispatch($server);
        }

        $verb = $this->option('sync') ? 'queried' : 'dispatched';
        $this->info("{$supported->count()} server(s) {$verb}.");

        if ($skipped->isNotEmpty()) {
            $protocols = $skipped->map(fn (Server $s) => $s->game->query_protocol->value)->unique()->implode(', ');
            $this->warn("{$skipped->count()} skipped — no driver for: {$protocols}");
        }

        return self::SUCCESS;
    }

    /**
     * Push what we are about to queue out of "due", so the next run cannot
     * queue it again.
     *
     * Without this the two halves of the system disagree about what has been
     * dealt with: `next_query_at` only moves when the job *runs*, and a job
     * waiting its turn leaves its server due, and oldest-due, and therefore
     * first in line for the next batch. A minute later it is queued again. The
     * queue then grows by the difference between what the dispatcher puts in
     * and what the workers take out — every minute, without bound — and the
     * copies are all of the same handful of servers, so the workers spend
     * themselves re-querying those while the rest of the catalog waits. It is
     * the failure that reads as "we need more workers" and is not.
     *
     * The lease is the base interval: a job that never runs — a worker killed
     * mid-batch, a queue flushed by hand — brings its server back after five
     * minutes rather than after the hour its tier might have asked for.
     *
     * Written before dispatching, not after. A worker can pick a job up and
     * finish it while this loop is still going, and an update landing after
     * that would overwrite the cadence the job just worked out with a lease
     * nobody is waiting on any more.
     *
     * @param  Collection<int, Server>  $servers
     */
    private function lease(Collection $servers): void
    {
        if ($servers->isEmpty()) {
            return;
        }

        // pluck rather than modelKeys: the host spread hands back a plain
        // collection, not an Eloquent one.
        Server::query()
            ->whereIn('id', $servers->pluck('id')->all())
            ->update(['next_query_at' => now()->addSeconds((int) config('monitoring.interval'))]);
    }

    /** @return Collection<int, Server> */
    private function dueServers(int $limit): Collection
    {
        return $this->due()->orderBy('next_query_at')->limit($limit)->get();
    }

    private function due(): Builder
    {
        return Server::query()->active()->where('next_query_at', '<=', now());
    }

    /**
     * Falling behind is the one failure mode that would otherwise be silent:
     * the cadence just quietly stretches and the graphs thin out. Say it loudly,
     * and leave a trace in the log for the runs nobody is watching.
     */
    private function reportBacklog(int $limit, int $heldBack): void
    {
        if ($heldBack > 0) {
            $this->warn("{$heldBack} server(s) held back by the per-host cap; they stay queued for the next run.");
        }

        $due = $this->due()->count();

        if ($due <= $limit) {
            return;
        }

        $oldest = $this->due()->min('next_query_at');
        $lag = $oldest ? (int) now()->diffInSeconds($oldest, absolute: true) : 0;

        $message = "Monitoring is behind: {$due} servers due, batch size {$limit}, oldest overdue by {$lag}s. "
            .'Cadence is degrading — raise batch_size, add queue workers, or widen the tier intervals.';

        $this->warn($message);
        Log::warning($message, ['due' => $due, 'limit' => $limit, 'lag_seconds' => $lag]);
    }
}
