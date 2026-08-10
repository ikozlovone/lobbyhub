<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /*
     * The status chips, which were costing a game page more than everything
     * else on it put together.
     *
     * Measured on the live catalog: a cold /api/games/counter-strike took 1.74
     * seconds against 74 ms warm, and the listing beside it 0.48 against 0.024.
     * That whole gap is ServerListing::statusFacets — one aggregate that reads
     * `status`, `players_online` and `players_max` for every server in the
     * game. The other four facets were given covering indexes in 2026_08_06 and
     * 2026_08_09 and are index-only already; this one was left reading the
     * heap:
     *
     *     Aggregate
     *       -> Bitmap Heap Scan on servers   Heap Blocks: exact=196
     *          -> Bitmap Index Scan on servers_listing_players_idx
     *
     * 196 heap blocks for 304 servers. Counter-Strike has around 150 000, at
     * 3.3 KB a row.
     *
     * The three columns go in INCLUDE rather than in the key: nothing orders or
     * ranges by them, they are only read, and payload columns keep the tree
     * shallow. `status` has to be carried even though it appears in the
     * predicate — the predicate proves which rows are in the index, not what
     * each one's value is, and the aggregate buckets by that value.
     *
     * Roughly thirty bytes a row instead of a heap page visit each.
     *
     * Why this is worth an index rather than a longer cache window: the numbers
     * it feeds are the fast-moving ones. `facets.statuses` counts online,
     * offline, empty and full across the whole game (Мониторинг.md §15.5), so
     * every poll that finds a server down moves them. Caching them for longer
     * would trade the freshness the chips exist for; making them cheap does not.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'create index concurrently if not exists servers_status_facet_idx '
            .'on servers (game_id) include (status, players_online, players_max) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index concurrently if exists servers_status_facet_idx');
    }
};
