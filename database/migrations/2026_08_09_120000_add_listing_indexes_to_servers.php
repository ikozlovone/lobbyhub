<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, so we opt out
     * of Laravel's default wrapping. In return the build is online: the game
     * pages keep serving while Postgres works.
     */
    public $withinTransaction = false;

    /*
     * The listing itself — the query behind every game page and the home page.
     *
     * It had no index it could use for order. Rust's page took 368 ms to hand
     * back twenty-five rows because Postgres lifted all 143 026 of the game's
     * servers out of the heap, 3.3 KB a row, and top-N sorted them; the count
     * that paginate() runs alongside walked the same rows again. Together that
     * is 36 273 + 28 630 blocks, about half a gigabyte of reads, for one view
     * of one page. Nothing stayed cached either — shared hit=473 against
     * read=36 273 — because 143k × 3.3 KB is more than shared_buffers holds,
     * so every visitor paid it again.
     *
     * Sorting could not use an index while the leading key was
     *
     *     case when promoted_until > now() then 0 else 1 end
     *
     * an expression over now(), which is not immutable and cannot be indexed.
     * A leading key that has to be computed means the whole order has to be
     * computed, which means reading everything. ServerListing no longer asks
     * for it — promoted servers are their own small query, pinned to the head
     * of the listing — and what is left is orderable from an index.
     *
     * Two of them, for the two sorts that carry the traffic: `rank`, which the
     * "All servers" tab uses, and `players`, which is the default. The other
     * five sorts stay on the old path deliberately; each index is a write cost
     * on a table the monitor updates constantly, and they are not asked for
     * often enough to earn it.
     *
     * The predicates repeat scopeActive + scopeVerified + the soft-delete scope
     * word for word. Postgres uses a partial index only when it can prove the
     * query's WHERE implies the index's predicate, and anything less faithful
     * leaves the index on disk unused.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // LIMIT 25 now stops the scan at the twenty-fifth row instead of after
        // the whole game. `id` is last because it is the listing's final
        // tiebreak, and carrying it also lets the count run index-only — the
        // same 143k rows, but over ~3 MB of index instead of 224 MB of heap.
        DB::statement(
            'create index concurrently if not exists servers_listing_rank_idx '
            .'on servers (game_id, rank_score desc, players_online desc, id) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown'"
        );

        DB::statement(
            'create index concurrently if not exists servers_listing_players_idx '
            .'on servers (game_id, players_online desc, id) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown'"
        );

        /*
         * The promoted query that now runs beside the listing.
         *
         * Small, but not free without this: there are only a handful of
         * promoted servers in a game and LIMIT cannot help find them, because
         * proving there is no sixth one means looking at all 143 026 rows. The
         * `promoted_until is not null` predicate keeps the index to the servers
         * anyone has ever paid for — tens of rows, not hundreds of thousands.
         */
        DB::statement(
            'create index concurrently if not exists servers_promoted_idx '
            .'on servers (game_id, promoted_until) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown' "
            .'and promoted_until is not null'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index concurrently if exists servers_listing_rank_idx');
        DB::statement('drop index concurrently if exists servers_listing_players_idx');
        DB::statement('drop index concurrently if exists servers_promoted_idx');
    }
};
