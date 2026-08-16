<?php

namespace App\Console\Commands;

use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Monitoring\HostSpread;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class DispatchServerQueries extends Command
{
    protected $signature = 'servers:query
        {--limit= : How many servers to dispatch (default: monitoring.batch_size)}
        {--game= : Only this game, by slug}
        {--server= : Query one server by slug, ignoring its schedule}
        {--ignore-schedule : Take servers that are not due, and that the Steam sweep has covered}
        {--sync : Run the queries inline instead of queueing them}';

    protected $description = 'Dispatch monitoring queries for servers that are due';

    public function handle(ServerQueryManager $manager, HostSpread $spread): int
    {
        $single = (bool) $this->option('server');
        $limit = (int) ($this->option('limit') ?: config('monitoring.batch_size'));

        // Said rather than silently polling the whole catalog, which is what a
        // filter that quietly matches nothing would otherwise do.
        if (($slug = $this->option('game')) && ! Game::query()->where('slug', $slug)->exists()) {
            $this->error("No game with slug [{$slug}].");

            return self::FAILURE;
        }

        // A named server is somebody debugging one address, and waiting on the
        // backlog is not what they asked for.
        if (! $single && $this->queueIsFull()) {
            return self::SUCCESS;
        }

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

        // "offered", not "dispatched", on the queued path: a server that already
        // has a query waiting is refused by the job's uniqueness lock, and
        // saying otherwise would make a batch full of repeats look like work.
        $verb = $this->option('sync') ? 'queried' : 'offered to the queue';
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
     * Which is also the limit of what a lease can do, and the reason QueryServer
     * is `ShouldBeUnique` as well. Five minutes is only long enough while the
     * queue drains inside five minutes; past that the server falls due again
     * with its first job still waiting, and the loop above resumes. The lease
     * keeps the dispatcher from reconsidering a server it has dealt with; the
     * lock keeps a second copy from existing when it does. A server refused by
     * the lock is still leased here, which is correct — a query for it is
     * already queued, and this run has nothing left to do about it.
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

    /**
     * Never-queried servers first, then the staleness order.
     *
     * A row we have never reached is not in the catalog yet: it is invisible to
     * every public listing until our own query confirms it exists (see
     * Server::scopeVerified). So it is worth more than another reading of a
     * server that already has three hundred — one of these turns into a
     * listing, the other into a data point. That is what makes an admin import
     * appear within a cycle or two instead of behind whatever backlog exists.
     *
     * It is a real priority, so it can really starve: a paste of fifty thousand
     * addresses is a hundred minutes at the default batch size during which
     * nothing else is polled. Bounded only by how much anyone pastes, which is
     * why the screen that does the pasting is behind /admin.
     *
     * @return Collection<int, Server>
     */
    private function dueServers(int $limit): Collection
    {
        return $this->due()
            ->orderByRaw('last_queried_at is not null')
            ->orderBy('next_query_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Due, and not already answered by the Steam sweep.
     *
     * A Source server that Steam is currently listing has told us everything a
     * packet would — players, map, version, bots, anti-cheat, the tag string —
     * so the packet buys a worker-second and nothing else. What the sweep
     * cannot say is why a server is *absent* from it: switched off, or running
     * without a game server login token and therefore invisible to Steam. Those
     * are the servers left here, and they are the ones worth the five-second
     * timeout.
     *
     * The window is generous on purpose. It is not "seen in the last sweep" but
     * "seen recently enough that the next sweep will cover it", so one failed
     * cycle does not dump twenty thousand servers back onto the queue.
     */
    private function due(): Builder
    {
        $query = Server::query()->active();

        if ($slug = $this->option('game')) {
            $query->whereHas('game', fn (Builder $game) => $game->where('slug', $slug));
        }

        /*
         * Both gates dropped together, because either alone leaves nothing.
         *
         * A game that has just been swept has fresh `steam_seen_at` on every
         * row and `next_query_at` pushed out by its tier, so the ordinary query
         * returns none of it — which is correct on the timetable and useless
         * when somebody is asking "is this game actually alive". Under this flag
         * the packets go out regardless, and that is the whole point of asking.
         *
         * The lease still applies, so a run started this way does not leave the
         * servers due for the next scheduled pass as well.
         */
        if ($this->option('ignore-schedule')) {
            return $query;
        }

        /*
         * Two windows, matched to the two passes.
         *
         * A server with players is in the five-minute occupied pass, so it is
         * trusted briefly and its disappearance — a busy server going down —
         * is noticed within about ten minutes. An empty one is only in the
         * half-hourly full pass and sits on the hour-long tier, so it is
         * trusted for an hour.
         *
         * One number could not do both, and the number chosen was shorter than
         * the pass that refreshed it: every empty server in the catalog was
         * uncovered for half of each cycle and fell to this query.
         */
        $populated = now()->subSeconds((int) config('monitoring.steam_trust_populated'));
        $quiet = now()->subSeconds((int) config('monitoring.steam_trust_quiet'));

        return $query
            ->where('next_query_at', '<=', now())
            ->where(fn (Builder $gate) => $gate
                ->whereNull('steam_seen_at')
                ->orWhere(fn (Builder $busy) => $busy
                    ->where('players_online', '>', 0)
                    ->where('steam_seen_at', '<', $populated))
                ->orWhere(fn (Builder $empty) => $empty
                    ->where('players_online', '=', 0)
                    ->where('steam_seen_at', '<', $quiet)));
    }

    /**
     * Whether there is room to add to the queue at all.
     *
     * A backlog past this size is not work waiting to be done, it is the
     * workers saying they cannot keep up — and another batch on top reaches no
     * server it would not have reached anyway. Left unbounded it grew to a
     * hundred thousand, and everything queued behind it was lost, sweeps
     * included.
     *
     * Nothing is dropped by stopping: the servers stay due, and the next run
     * with room takes them.
     */
    private function queueIsFull(): bool
    {
        $ceiling = (int) config('monitoring.max_queue_depth');

        if ($ceiling <= 0) {
            return false;
        }

        $depth = Queue::size(config('monitoring.queue'));

        if ($depth < $ceiling) {
            return false;
        }

        $message = "Monitoring queue is {$depth} deep, at or past the {$ceiling} ceiling — "
            .'nothing queued this run. The workers are behind, not the dispatcher.';

        $this->warn($message);
        Log::warning($message, ['depth' => $depth, 'ceiling' => $ceiling]);

        return true;
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
