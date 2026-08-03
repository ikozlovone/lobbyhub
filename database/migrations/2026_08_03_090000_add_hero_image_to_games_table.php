<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third picture, because one was doing three jobs.
     *
     * `cover_path` was the 460x215 Steam header, and it was drawn as the rail's
     * 28px thumbnail, as the catalog card, and stretched across the top of the
     * game page. The three want different crops: a thumbnail needs the logo
     * centred, a page banner needs width and somewhere for text to sit.
     *
     * So the roles split three ways and only the new one is added:
     *   icon_path  — the thumbnail, in the rail and beside a favourite
     *   cover_path — the card in the games list, which is what it always was
     *   hero_path  — the banner across a game page
     *
     * Nullable and unfilled: every game keeps rendering from cover_path until
     * somebody uploads a banner, because that is what the frontend falls back
     * to. Nothing has to be filled in for this to deploy.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('hero_path')->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('hero_path');
        });
    }
};
