<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a game lives outside this site: its own homepage, its docs, the
     * places its servers are set up.
     *
     * A column rather than a table. These have no counters, no facet pages and
     * nothing pointing at them — they are a short ordered list of labels and
     * addresses, edited as a whole, which is what `aliases` already is.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // [{"name": "FiveM Docs", "url": "https://docs.fivem.net/"}, ...]
            $table->jsonb('links')->nullable()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
