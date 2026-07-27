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
