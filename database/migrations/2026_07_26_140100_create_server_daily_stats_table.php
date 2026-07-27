<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily rollups, kept forever — this is what long-range graphs
     * (month / year / 3 years, like TrackyServer) read from.
     */
    public function up(): void
    {
        Schema::create('server_daily_stats', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->decimal('players_avg', 8, 2)->default(0);
            $table->unsignedInteger('players_min')->default(0);
            $table->unsignedInteger('players_peak')->default(0);
            $table->unsignedInteger('players_max')->default(0); // slots seen that day

            $table->unsignedSmallInteger('samples_count')->default(0);
            $table->unsignedSmallInteger('online_samples_count')->default(0);
            $table->decimal('uptime_percent', 5, 2)->default(0);

            $table->timestamps();

            $table->primary(['server_id', 'date']);
            $table->index(['date', 'players_peak']); // daily leaderboards
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_daily_stats');
    }
};
