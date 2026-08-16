<?php

namespace App\Services\Discovery;

use App\Models\Game;

/**
 * Reading one game's whole population out of Steam, however it is done.
 *
 * Two implementations, and the choice between them is a deployment decision
 * rather than a design one: the sequential sweep walks the axis tree depth
 * first and is the one whose behaviour every test pins, and the parallel sweep
 * asks a whole level at once and is faster by whatever Steam's latency happens
 * to be. They agree on what a bucket is, when it is saturated, and what comes
 * back — so the writer does not have to know which one it was handed.
 *
 * `$onServer` is called once per distinct server, as it arrives. Nothing here
 * returns a collection: the largest game on Steam does not fit in a worker's
 * memory twice over, and that is the constraint the whole path is shaped by.
 */
interface ServerSweep
{
    /**
     * @param  callable(DiscoveredServer): void  $onServer
     * @param  bool  $populatedOnly  Only servers with someone on them
     * @param  array<string, mixed>|null  $only  Addresses (`ip:gameport`) worth
     *                                           building; null takes everything.
     */
    public function stream(
        Game $game,
        callable $onServer,
        bool $populatedOnly = false,
        ?array $only = null,
    ): SweepResult;
}
