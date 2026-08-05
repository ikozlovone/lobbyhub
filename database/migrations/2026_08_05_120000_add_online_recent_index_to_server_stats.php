<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, so we opt out
     * of Laravel's default wrapping. In return the migration runs online: the
     * admin page and the monitor stay up while Postgres builds the index.
     */
    public $withinTransaction = false;

    /*
     * The admin page's "slowest servers" and every other 24-hour aggregation
     * that only cares about successful checks walked the whole recorded_at
     * index and then hashed everything by server_id — seven seconds at 200k
     * rows a day, and worse as the table grows to a rollup cycle's worth.
     *
     * The partial index covers exactly that path: only online points, sorted
     * by time, with the two columns aggregation needs already inside the
     * index. Offline samples do not carry a latency and would not answer
     * "which servers are slowest" anyway, so their absence is not a loss.
     */
    public function up(): void
    {
        DB::statement(
            'create index concurrently if not exists server_stats_online_recent_idx '
            .'on server_stats (recorded_at, server_id) include (latency_ms) '
            .'where is_online'
        );
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists server_stats_online_recent_idx');
    }
};
