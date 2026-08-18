<?php

namespace App\Services\Monitoring;

use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a `server_states` partition exists for every game.
 *
 * One partition per game means that a per-game listing or dispatcher query
 * touches one physical table, not the whole catalog — and that heavy writes
 * to one game's servers do not walk indexes belonging to another. The routing
 * is `game_id` alone, which is why the column is duplicated on `server_states`
 * even though `servers` already carries it (see the design doc).
 *
 * `CREATE TABLE IF NOT EXISTS` makes every method here idempotent, so the
 * migration that seeds partitions for existing games, the observer that
 * creates one for a newly added game, and a manual re-run all behave the
 * same.
 */
class ServerStatePartitionManager
{
    /**
     * @return bool  true if a partition was created (or already existed on
     *               pgsql), false when the platform doesn't partition.
     */
    public function ensureFor(Game $game): bool
    {
        if (! $this->isPartitioned()) {
            return false;
        }

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF server_states FOR VALUES IN (%d)',
            $this->partitionName($game->id),
            $game->id,
        ));

        return true;
    }

    /**
     * Seed partitions for every game already in the catalog.
     *
     * Called from the create-table migration so a fresh deploy has partitions
     * for the games that were already there. Safe to run again by hand.
     *
     * @return int  number of partitions ensured (0 on unpartitioned platforms)
     */
    public function ensureForAll(): int
    {
        if (! $this->isPartitioned()) {
            return 0;
        }

        $count = 0;

        // DB::table, not Game::query, so this runs inside a migration without
        // depending on how far the model layer has booted.
        foreach (DB::table('games')->pluck('id') as $id) {
            DB::statement(sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF server_states FOR VALUES IN (%d)',
                $this->partitionName((int) $id),
                (int) $id,
            ));
            $count++;
        }

        return $count;
    }

    /**
     * SQLite has no declarative partitioning — the test suite runs on it and
     * gets a plain `server_states` table from the migration. On that platform
     * there is nothing to route; the manager is a no-op and callers do not
     * have to know.
     */
    private function isPartitioned(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function partitionName(int $gameId): string
    {
        return "server_states_game_{$gameId}";
    }
}
