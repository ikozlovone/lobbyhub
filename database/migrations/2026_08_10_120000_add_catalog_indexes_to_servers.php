<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /*
     * The same listing again, but across every game.
     *
     * 2026_08_09 gave the per-game listing an index it could sort from. The
     * home page cannot use any of them: they lead with `game_id`, and it has no
     * game to match. So the four sections it is built from — busiest, ranked,
     * newest, recently wiped — each fell back to reading every active server in
     * the catalog and top-N sorting it:
     *
     *     Limit  -> Sort (top-N heapsort)
     *                 -> Bitmap Heap Scan on servers  Heap Blocks: exact=200
     *
     * for twelve rows. These are the same partial indexes, led by the sort
     * column instead.
     *
     * Search matters more than the sections do, because the sections go through
     * the listing cache and pay this at most once a minute, while `q` is
     * deliberately not cached — free text has no bounded keyspace — so every
     * search that survives the debounce runs one of these for real.
     *
     * What search gets is an ordered path the planner did not have, not a
     * guarantee it takes it: `lower(name) like '%…%'` cannot be indexed, so the
     * choice is between walking this index in player order and filtering until
     * twelve rows match, or gathering the matches and sorting those. Measured
     * here, it walks the index for a common term and gathers-and-sorts for a
     * rare one, which is the right way round — sorting a handful of matches is
     * cheap, and it is the common term that would otherwise sort the catalog.
     *
     * The predicates repeat scopeActive + scopeVerified + the soft-delete scope
     * word for word, as before. Postgres uses a partial index only when it can
     * prove the query's WHERE implies the index's predicate.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * The expensive one to keep, and the only one of the four that is.
         *
         * `players_online` changes on almost every poll, so every write moves
         * an entry in here. It is worth it because this ordering is what both
         * the home page's first section and every search are sorted by — and
         * because the column is indexed already, by the per-game index, so this
         * is a second index update on a write that was never going to be HOT
         * anyway rather than a new class of cost.
         */
        DB::statement(
            'create index concurrently if not exists servers_catalog_players_idx '
            .'on servers (players_online desc, id) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown'"
        );

        // Nearly free: rank_score is written by ranking:recompute and by nothing
        // else, so this moves once every fifteen minutes rather than per poll.
        DB::statement(
            'create index concurrently if not exists servers_catalog_rank_idx '
            .'on servers (rank_score desc, players_online desc, id) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown'"
        );

        /*
         * Smaller than the others by the width of the catalog: most games have
         * no wipe concept at all, so `wiped_at is not null` keeps this to the
         * servers the section is actually about. It moves when a server wipes,
         * which for Rust is weekly and for everything else never.
         */
        DB::statement(
            'create index concurrently if not exists servers_catalog_wiped_idx '
            .'on servers (wiped_at desc, id) '
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown' "
            .'and wiped_at is not null'
        );

        /*
         * "Newest" already had a plan — Index Scan Backward on the primary key
         * — and on the data here it is a good one. It is indexed anyway because
         * of what the primary key cannot know: discovery imports candidates by
         * the thousand and they enter as `unknown`, so the newest ids are mostly
         * rows this listing filters out. The backward scan then walks however
         * many of them stand between the end of the table and twelve verified
         * servers, and nothing bounds that number.
         *
         * The cheapest index in the file to keep: `id` never changes, so a row
         * enters and leaves this exactly once.
         */
        DB::statement(
            'create index concurrently if not exists servers_catalog_newest_idx '
            .'on servers (id desc) '
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

        DB::statement('drop index concurrently if exists servers_catalog_players_idx');
        DB::statement('drop index concurrently if exists servers_catalog_rank_idx');
        DB::statement('drop index concurrently if exists servers_catalog_wiped_idx');
        DB::statement('drop index concurrently if exists servers_catalog_newest_idx');
    }
};
