<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people are playing the game itself, as Steam counts them.
 *
 * A different number from `players_online` next to it, and the difference is
 * worth being clear about: that one is the sum of the players our monitor
 * found on the game's servers, and this one is everybody in the game anywhere
 * on Steam — single-player, matchmaking, official servers we do not list, and
 * the servers we do. For Rust the two are close. For a game whose players
 * never touch a dedicated server the second is large and the first is zero,
 * which is a fact about the game rather than a gap in the monitoring.
 *
 * Denormalised here for the same reason the server counters are: a game page
 * and the rail read it on every request, and ClickHouse holds the history but
 * should not be in the path of a page render. `steamstats` writes all four at
 * the end of a tick; nothing in PHP writes them.
 *
 * `steam_chart_rank` is nullable rather than zero-defaulted because "not in
 * Steam's top 100" is not rank zero — it is no rank, and a column that says 0
 * would sort a game nobody plays above Counter-Strike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('steam_players_online')->default(0);
            // Steam's own 24-hour peak, as published beside the chart. Only
            // the top 100 carry one, so a game below it keeps the last peak
            // Steam published for it, or zero if it has never charted.
            $table->unsignedInteger('steam_players_peak')->default(0);
            $table->unsignedSmallInteger('steam_chart_rank')->nullable();
            $table->timestamp('steam_stats_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn([
                'steam_players_online',
                'steam_players_peak',
                'steam_chart_rank',
                'steam_stats_synced_at',
            ]);
        });
    }
};
