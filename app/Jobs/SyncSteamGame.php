<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\Discovery\SteamCatalogSync;
use App\Services\Discovery\SteamServerSweep;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One game's whole Steam population, read and written.
 *
 * On the queue rather than in the scheduler, for the same reason the per-server
 * queries are: the sweep for Counter-Strike is a few dozen HTTP round trips and
 * a hundred thousand rows, and doing forty-five games in a row inside a
 * once-a-minute command would still be running when the next one started.
 * Spread across the workers, the games go at once.
 *
 * Unique per game, because that is exactly the collision the queue would
 * otherwise create: a slow game whose sweep outlives the interval would have a
 * second copy queued on top of it, and both would write the same rows.
 */
class SyncSteamGame implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A failed sweep is not retried: the next cycle is a few minutes away and will be fresher. */
    public int $tries = 1;

    /**
     * Longer than the worker's default of sixty seconds, which would kill the
     * biggest game a third of the way through. Counter-Strike measured at a few
     * dozen requests; this leaves room for that to double before it matters.
     */
    public int $timeout = 900;

    public function __construct(public Game $game)
    {
        $this->onQueue(config('monitoring.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->game->getKey();
    }

    public function uniqueFor(): int
    {
        return (int) config('monitoring.unique_for', 3600);
    }

    public function handle(SteamServerSweep $sweep, SteamCatalogSync $sync): void
    {
        $report = $sync->run($this->game, $sweep);

        /*
         * Only the gap is logged, and only when there is one.
         *
         * A sweep that finishes is unremarkable and happens for forty-five games
         * every few minutes; one that came back short means Steam is holding
         * servers the partition could not reach, and the catalog is quietly
         * missing them. That is worth a line in the log even though the command
         * prints it too — the command is watched by a person, once.
         */
        if ($report->truncated > 0) {
            Log::warning('Steam sweep truncated', [
                'game' => $this->game->slug,
                'buckets' => $report->truncated,
                'found' => $report->found,
                'requests' => $report->requests,
            ]);
        }
    }
}
