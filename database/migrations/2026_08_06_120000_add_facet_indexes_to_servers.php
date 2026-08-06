<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, so we opt out
     * of Laravel's default wrapping. In return the build is online: /admin, the
     * public game pages and the monitor keep serving while Postgres works.
     */
    public $withinTransaction = false;

    /*
     * The map and country chips on a game page each ran an aggregate over
     * every server the game has. The existing index picked the game_id side
     * fast enough — 148 898 pointers in a second and a half for CS2 — but map
     * and country_id are not in it, so every one of those pointers turned into
     * a heap visit for a byte of column. Twenty seconds for the map chips at
     * 129 000 servers, and every additional game one makes it slower.
     *
     * Partial covering indexes fix both: only the rows the aggregate looks at,
     * with the column the aggregate reads carried in the index. Grouping is
     * then an index-only scan.
     *
     * The predicates mirror the filters in ServerListing::mapFacets and
     * countryFacets exactly. Postgres will use the partial index only when the
     * query's WHERE clause implies the predicate; anything less faithful and
     * the planner picks the full-table path and the index is dead weight on
     * disk.
     */
    public function up(): void
    {
        DB::statement(
            'create index concurrently if not exists servers_map_facet_idx '
            ."on servers (game_id, map) "
            .'where is_active '
            .'and deleted_at is null '
            ."and status <> 'unknown' "
            .'and map is not null '
            ."and map <> ''"
        );

        DB::statement(
            'create index concurrently if not exists servers_country_facet_idx '
            .'on servers (game_id, country_id) '
            .'where is_active '
            .'and deleted_at is null '
            .'and country_id is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists servers_map_facet_idx');
        DB::statement('drop index concurrently if exists servers_country_facet_idx');
    }
};
