<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Addressing — what the monitor connects to
            $table->string('host');                          // domain or IP as submitted
            $table->unsignedInteger('port');
            $table->unsignedInteger('query_port')->nullable(); // null = fall back to the game's default
            $table->string('ip_address', 45)->nullable();     // resolved, used for geo lookup

            // Catalog identity
            $table->string('slug')->unique();                // /servers/hypixel
            $table->string('name');
            $table->text('description')->nullable();

            // SEO taxonomy
            $table->foreignId('game_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city')->nullable();

            // Current monitoring snapshot
            $table->string('status', 16)->default('unknown'); // App\Enums\ServerStatus
            $table->unsignedInteger('players_online')->default(0);
            $table->unsignedInteger('players_max')->default(0);
            $table->string('map')->nullable();
            $table->string('reported_version')->nullable();   // raw string from the query response
            $table->decimal('uptime_percent', 5, 2)->nullable();
            $table->timestamp('last_queried_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->unsignedSmallInteger('failed_queries_count')->default(0);

            // Ownership — the claimed-server model from GameMonitoring
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();

            // Owner-editable presentation
            $table->string('website_url')->nullable();
            $table->string('discord_url')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('icon_path')->nullable();

            // Ranking and promotion
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rank_score')->default(0);
            $table->timestamp('promoted_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // One server per address per game
            $table->unique(['game_id', 'host', 'port']);

            // Catalog listings: top by online / by score, per game
            $table->index(['game_id', 'is_active', 'players_online']);
            $table->index(['game_id', 'is_active', 'rank_score']);

            // SEO landings
            $table->index(['game_id', 'country_id', 'is_active']);
            $table->index(['game_id', 'game_version_id', 'is_active']);

            // Monitoring queue picks the stalest rows
            $table->index(['status', 'last_queried_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
