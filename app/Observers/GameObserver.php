<?php

namespace App\Observers;

use App\Models\Game;
use App\Services\Monitoring\ServerStatePartitionManager;

/**
 * Whatever creates a game creates its `server_states` partition too.
 *
 * The alternative — creating partitions lazily on first write — would put a
 * DDL statement in front of what should be a bulk INSERT (the first Steam
 * sweep of the new game), and the ensuing "relation does not exist" the
 * first time anything reads from the new partition through the parent is
 * exactly the class of failure this class exists to avoid.
 *
 * Idempotent through `CREATE TABLE IF NOT EXISTS` in the manager, so a re-seed
 * or a manual `Game::create` is safe.
 */
class GameObserver
{
    public function __construct(
        private readonly ServerStatePartitionManager $partitions,
    ) {}

    public function created(Game $game): void
    {
        $this->partitions->ensureFor($game);
    }
}
