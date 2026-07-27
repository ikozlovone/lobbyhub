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
             * The port players connect to, as reported by the server itself.
             * Kept apart from `port`: that one is submitted by the owner and is
             * part of the (game_id, host, port) identity, so it must not be
             * rewritten on every poll.
             */
            $table->unsignedInteger('game_port')->nullable()->after('port');

            /**
             * From Rust's `born` tag. Strictly it is when the server last
             * started, which for Rust normally means a wipe — but a plain
             * restart moves it too, so treat it as "wipe, approximately".
             */
            $table->timestamp('wiped_at')->nullable()->after('map');

            /** Rust's `qp` tag: players waiting in the join queue. */
            $table->unsignedSmallInteger('players_queued')->default(0)->after('players_max');
        });

        Schema::table('servers', function (Blueprint $table) {
            // "Freshly wiped first" is the primary sort on a Rust listing.
            $table->index(['game_id', 'is_active', 'wiped_at']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['game_id', 'is_active', 'wiped_at']);
            $table->dropColumn(['game_port', 'wiped_at', 'players_queued']);
        });
    }
};
