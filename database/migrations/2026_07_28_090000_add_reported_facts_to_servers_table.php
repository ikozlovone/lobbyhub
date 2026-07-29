<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            /**
             * Both come out of the A2S_INFO packet, which the Source driver has
             * always had to read past to reach the fields after them — they were
             * parsed and thrown away. Nullable because no other protocol has the
             * concept: a Minecraft server neither runs bots nor knows about VAC,
             * and "we cannot know" is not the same answer as "none" or "off".
             */
            $table->unsignedSmallInteger('bots')->nullable()->after('players_queued');
            $table->boolean('vac_enabled')->nullable()->after('bots');

            /**
             * The last time a query failed.
             *
             * `last_online_at` alone cannot answer "how reliable is this?" — it
             * says when the server was last up, not when it was last down. The
             * pair does: online an hour ago and offline two days ago describes a
             * server that has been solid since.
             */
            $table->timestamp('last_offline_at')->nullable()->after('last_online_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['bots', 'vac_enabled', 'last_offline_at']);
        });
    }
};
