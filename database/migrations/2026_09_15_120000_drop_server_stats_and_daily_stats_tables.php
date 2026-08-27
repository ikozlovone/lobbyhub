<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the two PHP-era monitoring tables.
 *
 * `server_stats` held one row per query attempt for the LobbyHub PHP job to
 * write to; `server_daily_stats` was its nightly rollup. Both were replaced by
 * ClickHouse — the Go sweeper writes `lobbyhub_stats.server_players_raw` every
 * ten minutes, and the frontend graph reads from there via `ServerHistory`.
 *
 * The PHP writers were removed one release earlier: QueryServer::recordSample
 * is gone and `stats:rollup` was deleted alongside its models. Both tables
 * have therefore been dead-writing for that release, and this migration
 * finishes the job.
 *
 * DO NOT run this migration until at least two weeks after the ServerHistory
 * cut-over — the interval is the safety window that lets us fall back to the
 * old data if the CH pipeline surprises us. Between then and now the tables
 * consume disk (~30-50 GB combined on the current catalog) and nothing else,
 * so leaving them around costs storage but not correctness.
 *
 * `down()` restores the shape of the tables so a rollback works, but no data
 * comes back — a Postgres migration cannot resurrect what a `dropIfExists`
 * threw out. If you find yourself running the down path, restore from the
 * pre-drop backup instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('server_stats');
        Schema::dropIfExists('server_daily_stats');
    }

    public function down(): void
    {
        Schema::create('server_stats', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->boolean('is_online');
            $table->unsignedInteger('players_online')->default(0);
            $table->unsignedInteger('players_max')->default(0);
            $table->unsignedSmallInteger('latency_ms')->nullable();
            $table->primary(['server_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::create('server_daily_stats', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('players_avg', 8, 2)->default(0);
            $table->unsignedInteger('players_min')->default(0);
            $table->unsignedInteger('players_peak')->default(0);
            $table->unsignedInteger('players_max')->default(0);
            $table->unsignedSmallInteger('samples_count')->default(0);
            $table->unsignedSmallInteger('online_samples_count')->default(0);
            $table->decimal('uptime_percent', 5, 2)->default(0);
            $table->timestamps();
            $table->primary(['server_id', 'date']);
            $table->index(['date', 'players_peak']);
        });
    }
};
