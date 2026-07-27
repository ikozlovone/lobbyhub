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
             * Everything a server publishes about itself beyond the basic status:
             * world size and seed, entity count, server FPS, its own banner and
             * map images, description and website.
             *
             * Kept as jsonb rather than a column per field because the set is
             * game-specific — Rust's rules have nothing in common with a
             * Garry's Mod server's. Anything we start filtering or sorting on
             * gets promoted to a real column at that point.
             */
            $table->jsonb('details')->nullable()->after('motd');

            /** Details change slowly, so they are refreshed on their own cadence. */
            $table->timestamp('details_synced_at')->nullable()->after('details');

            /** Steam's own id for the server, from the A2S extra-data block. */
            $table->string('steam_id', 24)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['details', 'details_synced_at', 'steam_id']);
        });
    }
};
