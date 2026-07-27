<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            /**
             * Steam application id.
             *
             * Two uses: it addresses the official store artwork on Steam's CDN,
             * and it is the filter the Steam Web API needs for server discovery.
             * Null for games that are not on Steam at all, such as Minecraft.
             */
            $table->unsignedInteger('steam_appid')->nullable()->unique()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('steam_appid');
        });
    }
};
