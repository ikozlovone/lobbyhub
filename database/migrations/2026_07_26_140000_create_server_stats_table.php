<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw monitoring samples — one row per successful or failed query.
     * Short-lived: `stats:rollup` folds them into server_daily_stats and prunes.
     * No surrogate key on purpose: (server_id, recorded_at) is both the natural
     * key and the access path for "this server's history over a range".
     */
    public function up(): void
    {
        Schema::create('server_stats', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');

            $table->boolean('is_online');
            $table->unsignedInteger('players_online')->default(0);
            $table->unsignedInteger('players_max')->default(0);
            $table->unsignedSmallInteger('latency_ms')->nullable();

            $table->primary(['server_id', 'recorded_at']);
            $table->index('recorded_at'); // pruning old samples
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_stats');
    }
};
