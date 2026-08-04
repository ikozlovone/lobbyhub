<?php

namespace App\Console\Commands;

use App\Jobs\QueryServer;
use App\Models\Server;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Free servers whose queued query no longer exists.
 *
 * QueryServer is unique per server for as long as a copy of it is queued, and
 * the lock that enforces that lives in the cache rather than in the queue. So
 * emptying the queue by hand — `queue:clear`, a flush after a bad backlog —
 * deletes the jobs and leaves the locks behind. Every server that had one is
 * then refused a new query until the lock expires on its own, which is up to
 * `monitoring.unique_for`: an hour of a monitor that looks healthy and is not
 * polling anything.
 *
 * This is the other half of that operation. It is safe to run when there is
 * nothing to do — releasing a lock nobody holds is a no-op — so it belongs in
 * the flush procedure unconditionally rather than being reached for after
 * somebody notices the gap.
 */
class ReleaseMonitoringLocks extends Command
{
    protected $signature = 'monitoring:unlock
        {--force : Release even while queries are still queued}';

    protected $description = 'Release uniqueness locks left behind by queries that are no longer queued';

    public function handle(Cache $cache): int
    {
        $queued = Queue::size(config('monitoring.queue'));

        /*
         * Refusing rather than warning, because the mistake is not obvious from
         * the outside: a lock released while its job is still queued lets the
         * dispatcher queue a second copy of the same query, which is the exact
         * failure the lock exists to prevent. Run this against a full queue and
         * you would be undoing the fix, one server at a time, and the symptom
         * would show up hours later as a queue that grows on its own.
         */
        if ($queued > 0 && ! $this->option('force')) {
            $this->components->error(
                "{$queued} quer(ies) are still queued. Releasing their locks would let the dispatcher "
                .'queue a second copy of each. Empty the queue first, or pass --force if you know better.'
            );

            return self::FAILURE;
        }

        $released = 0;
        $servers = 0;

        Server::query()->select(['id'])->chunkById(1000, function ($chunk) use ($cache, &$released, &$servers) {
            foreach ($chunk as $server) {
                $servers++;

                /*
                 * Taking the lock to find out whether anyone held it.
                 *
                 * There is no "is this held" on the cache lock contract, and
                 * forceRelease answers nothing — it deletes and returns. Trying
                 * to acquire does answer: it fails exactly when somebody else
                 * holds it. Won locks are handed straight back, so the only
                 * lasting effect is on the ones that were actually stuck.
                 */
                $key = UniqueLock::getKey(new QueryServer($server));
                $lock = $cache->lock($key, 1);

                if ($lock->get()) {
                    $lock->release();

                    continue;
                }

                $lock->forceRelease();
                $released++;
            }
        });

        $this->components->info("Checked {$servers} server(s); released {$released} stuck lock(s).");

        return self::SUCCESS;
    }
}
