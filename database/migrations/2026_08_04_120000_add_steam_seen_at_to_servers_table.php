<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Steam's own server list last carried this server.
 *
 * The column exists to keep two monitors from doing the same job. A Source
 * server that Steam is listing has already told us everything a query would —
 * players, map, version, bots, anti-cheat, the tag string — so sending it a
 * packet buys nothing but a worker-second. Absence is the interesting case, and
 * that is what the dispatcher is left to chase.
 *
 * Nullable on purpose: null means "never seen in a Steam list", which is true
 * of every Minecraft and FiveM server and of any Source server whose owner
 * never set a game server login token. Those are polled the way they always
 * were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('steam_seen_at')->nullable()->after('last_online_at');

            // The dispatcher's due-servers query filters on this next to
            // is_active and next_query_at, which already have their own index.
            $table->index(['is_active', 'steam_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'steam_seen_at']);
            $table->dropColumn('steam_seen_at');
        });
    }
};
