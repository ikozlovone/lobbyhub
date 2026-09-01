<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When gamemonitoring.net was last seen listing this server.
 *
 * The catalog holds servers nobody else does, and that is not always a good
 * thing: an address discovered once and never listed anywhere else is usually
 * a machine that has stopped existing, and a page for it is a thin page we
 * asked a search engine to index. Knowing which of our rows the nearest
 * competitor also carries is what makes it safe to delete the rest.
 *
 * A timestamp rather than a flag, and nullable, for the same reason
 * `steam_seen_at` is one: `seen` and `when` are the same question asked twice,
 * and a mark with no date on it cannot be told apart from a mark left by a
 * parse that ran in March. Null means we have never matched this server there
 * — which is also what every row says the day before the first parse runs, so
 * a deletion pass must judge against when the parse itself last completed, not
 * against this column alone.
 *
 * No index. The column is written by a periodic parser and read by an
 * occasional cleanup pass over the whole table, and neither is a query shape
 * an index would rescue; a partial one on `IS NULL` is a line away if the
 * cleanup ever becomes routine.
 *
 * On `servers` rather than `server_states`: this is a catalog fact about a row,
 * on the cold side that a sweep never touches, not a measurement the monitor
 * rewrites. BattleMetrics gets a column of its own next to it when its parser
 * lands — two competitors is not a set that wants a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('gamemonitoring_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('gamemonitoring_seen_at');
        });
    }
};
