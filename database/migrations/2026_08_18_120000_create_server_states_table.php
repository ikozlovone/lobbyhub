<?php

use App\Services\Monitoring\ServerStatePartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits every field the monitor rewrites out of `servers` and into its own
 * table, partitioned by game.
 *
 * `servers` is the cold catalog: identity, ownership, presentation, ranking.
 * `server_states` is the hot snapshot: player counts, status, MOTD, map,
 * schedule columns. Steam Master sync alone updates hundreds of thousands of
 * rows every ten minutes — under one table that meant every write walked
 * every catalog index and produced dead tuples across a table used for cold
 * reads. Splitting keeps the two workloads from stepping on each other and
 * lets each side of the schema keep only the indexes it actually needs.
 *
 * PARTITION BY LIST (game_id): a listing filtered to one game touches one
 * physical table, so an index on that partition is per-game by construction
 * and does not need `game_id` at the leading position. Writes to CS2 rows do
 * not fragment the Rust partition's indexes and vice versa. The routing is
 * `game_id`, which is why the column lives on `server_states` even though
 * `servers` already has it.
 *
 * SQLite has no declarative partitioning; the test suite runs on it, so the
 * table is created flat there. The columns are identical either way, so app
 * code sees one table on both platforms.
 *
 * See the split's write path (SteamCatalogSync, QueryServer) and read path
 * (ServerListing, ServerRanking, ServerResource) for how the app now uses
 * this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->createPartitioned();
        } else {
            $this->createPlain();
        }

        // A partition per game that already exists — a fresh deploy would
        // otherwise have a parent table and nowhere to write. Idempotent, so
        // rerunning the migration is safe.
        app(ServerStatePartitionManager::class)->ensureForAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('server_states');
    }

    /**
     * Postgres: declarative LIST partition by `game_id`. The primary key must
     * include the partition key, hence the composite `(game_id, server_id)`.
     */
    private function createPartitioned(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE server_states (
                server_id            bigint       NOT NULL,
                game_id              bigint       NOT NULL,
                status               varchar(16)  NOT NULL DEFAULT 'unknown',
                players_online       integer      NOT NULL DEFAULT 0,
                players_max          integer      NOT NULL DEFAULT 0,
                players_queued       smallint     NOT NULL DEFAULT 0,
                bots                 smallint,
                vac_enabled          boolean,
                map                  varchar(255),
                reported_version     varchar(255),
                motd                 text,
                wiped_at             timestamp(0),
                steam_id             varchar(24),
                game_port            integer,
                last_queried_at      timestamp(0),
                last_online_at       timestamp(0),
                last_offline_at      timestamp(0),
                next_query_at        timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                failed_queries_count smallint     NOT NULL DEFAULT 0,
                steam_seen_at        timestamp(0),
                uptime_percent       numeric(5, 2),
                created_at           timestamp(0),
                updated_at           timestamp(0),
                PRIMARY KEY (game_id, server_id)
            ) PARTITION BY LIST (game_id)
        SQL);
    }

    /**
     * SQLite fallback: a plain table with the same columns. The primary key
     * is the same composite — the app treats the shape as canonical, not as
     * a consequence of partitioning.
     */
    private function createPlain(): void
    {
        Schema::create('server_states', function (Blueprint $table) {
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('game_id');
            $table->string('status', 16)->default('unknown');
            $table->integer('players_online')->default(0);
            $table->integer('players_max')->default(0);
            $table->smallInteger('players_queued')->default(0);
            $table->smallInteger('bots')->nullable();
            $table->boolean('vac_enabled')->nullable();
            $table->string('map')->nullable();
            $table->string('reported_version')->nullable();
            $table->text('motd')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->string('steam_id', 24)->nullable();
            $table->integer('game_port')->nullable();
            $table->timestamp('last_queried_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->timestamp('last_offline_at')->nullable();
            $table->timestamp('next_query_at')->useCurrent();
            $table->smallInteger('failed_queries_count')->default(0);
            $table->timestamp('steam_seen_at')->nullable();
            $table->decimal('uptime_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->primary(['game_id', 'server_id']);
        });
    }
};
