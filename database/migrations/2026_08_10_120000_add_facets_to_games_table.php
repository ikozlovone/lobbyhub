<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Precomputed facets, so a cold cache stops costing seconds.
     *
     * The facets on a game page — status buckets, maps, countries, modes,
     * versions — are five aggregates that scan the game's rows. With a hundred
     * thousand of them for CS2 the cold path was three and a half seconds even
     * after the partial indexes turned the map aggregate into an index-only
     * scan. The nginx cache in front and the Redis cache behind both mask it
     * most of the time, but between them lies a moment every so often when
     * both have expired and the next visitor pays the whole bill.
     *
     * A column removes that moment. The scheduled `facets:refresh` writes into
     * it every few minutes, and the controller reads it as a plain array —
     * one row already loaded, no aggregate to run, no cache to miss.
     *
     * `facets_synced_at` is the row's own honesty: if the schedule ever stops,
     * the column keeps its last good answer forever, which is exactly the kind
     * of lag that reads as right and never is. The timestamp is how a health
     * check catches it.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('facets')->nullable()->after('links');
            $table->timestamp('facets_synced_at')->nullable()->after('facets');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['facets', 'facets_synced_at']);
        });
    }
};
