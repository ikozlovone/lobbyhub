<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\Monitoring\ServerStatePartitionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copy hot fields out of `servers` and into `server_states`, one game at a time.
 *
 * The offline half of the split-tables migration: after `create_server_states`
 * has laid down the partitions and before the app is started back up. Every
 * writer already targets `server_states`, so a game whose data has not been
 * copied here yet would come up empty on every page — the state row would not
 * exist and readers do LEFT JOIN nothing.
 *
 * One `INSERT ... SELECT` per game, which on Postgres routes into that game's
 * partition through the parent. `ON CONFLICT DO NOTHING` on the composite key
 * makes the whole thing idempotent — safe to rerun as many times as it takes
 * to sort out a partition that failed halfway. It does mean an already-copied
 * row is not refreshed by rerunning; that is the wrong shape of tool for
 * mid-copy drift, and there is no mid-copy drift here because the app is off.
 */
class BackfillServerStates extends Command
{
    protected $signature = 'server-states:backfill
        {--only= : Backfill only this game by slug (default: every game with servers)}
        {--verify : After each game, count rows on both sides and complain if they do not match}
        {--dry-run : Report how many rows each game would copy, without copying}';

    protected $description = 'Copy hot fields from servers into server_states, per game partition';

    /**
     * Columns copied. Order matters — the INSERT list and the SELECT list are
     * built from this same array, so they line up by construction.
     *
     * `server_id` is `servers.id`. `game_id`, `updated_at`, `created_at` are
     * the three the migration turned into required columns on state; the rest
     * are copied as-is.
     */
    private const COLUMNS = [
        'status',
        'players_online',
        'players_max',
        'players_queued',
        'bots',
        'vac_enabled',
        'map',
        'reported_version',
        'motd',
        'wiped_at',
        'steam_id',
        'game_port',
        'last_queried_at',
        'last_online_at',
        'last_offline_at',
        'next_query_at',
        'failed_queries_count',
        'steam_seen_at',
        'uptime_percent',
    ];

    public function handle(ServerStatePartitionManager $partitions): int
    {
        $only = $this->option('only');
        $verify = (bool) $this->option('verify');
        $dry = (bool) $this->option('dry-run');

        $games = Game::query()
            ->when($only, fn ($q) => $q->where('slug', $only))
            ->orderBy('id')
            ->get(['id', 'slug', 'name']);

        if ($games->isEmpty()) {
            $this->error($only ? "No game with slug [{$only}]." : 'No games in catalog.');

            return self::FAILURE;
        }

        $totalCopied = 0;
        $totalSkipped = 0;
        $mismatches = 0;

        foreach ($games as $game) {
            [$copied, $alreadyPresent, $mismatch] = $this->backfill($game, $partitions, $verify, $dry);

            $totalCopied += $copied;
            $totalSkipped += $alreadyPresent;

            if ($mismatch) {
                $mismatches++;
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%s: %d row(s) copied, %d row(s) already present, %d game(s) with mismatch.',
            $dry ? 'Dry run' : 'Done',
            $totalCopied,
            $totalSkipped,
            $mismatches,
        ));

        return $mismatches > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy one game's servers into its state partition.
     *
     * @return array{0: int, 1: int, 2: bool}  copied, already-present, mismatch-detected
     */
    private function backfill(Game $game, ServerStatePartitionManager $partitions, bool $verify, bool $dry): array
    {
        // Cheap up front: `IF NOT EXISTS` on the manager makes this a no-op
        // when the migration already seeded a partition for this game.
        $partitions->ensureFor($game);

        $eligible = (int) DB::table('servers')
            ->where('game_id', $game->id)
            ->whereNull('deleted_at')
            ->count();

        $existing = (int) DB::table('server_states')->where('game_id', $game->id)->count();

        $this->components->twoColumnDetail(
            "<fg=gray>{$game->slug}</>",
            "servers: {$eligible}, states: {$existing}",
        );

        if ($eligible === 0) {
            return [0, 0, false];
        }

        if ($dry) {
            $would = max(0, $eligible - $existing);
            $this->line("  would copy {$would} row(s)");

            return [0, 0, false];
        }

        $inserted = $this->copy($game);
        $after = (int) DB::table('server_states')->where('game_id', $game->id)->count();

        // Rows in `servers` that already had a state row before this call
        // ran — either from a previous backfill attempt or because the app
        // wrote one after the migration.
        $alreadyPresent = max(0, $eligible - $inserted);

        $this->line("  inserted {$inserted}, already present {$alreadyPresent}");

        $mismatch = false;

        if ($verify && $after !== $eligible) {
            $this->warn("  mismatch: {$eligible} eligible servers, {$after} state rows after copy");
            $mismatch = true;
        }

        return [$inserted, $skipped, $mismatch];
    }

    /**
     * One `INSERT ... SELECT` for the whole game — Postgres routes rows into
     * the partition by `game_id`, and one statement is enough because there
     * is nothing to update, only to seed.
     *
     * `ON CONFLICT (game_id, server_id) DO NOTHING` skips any row a previous
     * attempt already wrote, so a rerun after a partial failure picks up
     * where it left off rather than fighting the PK.
     */
    private function copy(Game $game): int
    {
        $insertColumns = array_merge(['server_id', 'game_id'], self::COLUMNS, ['created_at', 'updated_at']);
        $selectColumns = array_merge(['id', 'game_id'], self::COLUMNS, ['updated_at', 'updated_at']);

        $insertList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $insertColumns));
        $selectList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $selectColumns));

        $sql = "insert into \"server_states\" ({$insertList}) "
            ."select {$selectList} from \"servers\" "
            .'where "game_id" = ? and "deleted_at" is null '
            .$this->conflictClause();

        return DB::affectingStatement($sql, [$game->id]);
    }

    /**
     * `ON CONFLICT` is Postgres syntax; SQLite spells it `ON CONFLICT ...
     * DO NOTHING` too, but the test suite here would never hit a conflict
     * (fresh in-memory DB) so it is fine either way. Kept driver-branched so
     * a future SQLite change does not silently break the escape hatch.
     */
    private function conflictClause(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'on conflict ("game_id", "server_id") do nothing',
            'sqlite' => 'on conflict ("game_id", "server_id") do nothing',
            default => '',
        };
    }
}
