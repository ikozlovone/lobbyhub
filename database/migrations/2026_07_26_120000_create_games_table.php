<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('slug')->unique();               // /games/minecraft
            $table->string('name');
            $table->string('short_name', 32)->nullable();    // "MC", "GTA RP"
            $table->jsonb('aliases')->nullable();           // search synonyms: ["mc", "майнкрафт"]

            // Monitoring
            $table->string('query_protocol', 32);           // App\Enums\QueryProtocol
            $table->unsignedInteger('default_port');
            $table->unsignedInteger('default_query_port')->nullable(); // null = same as game port

            // Catalog / landing
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('has_versions')->default(false); // enables /version/... pages
            $table->string('icon_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('accent_color', 7)->nullable();   // #RRGGBB
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();

            // Denormalized counters, refreshed by the monitoring job
            $table->unsignedInteger('servers_count')->default(0);
            $table->unsignedInteger('online_servers_count')->default(0);
            $table->unsignedInteger('players_online')->default(0);
            $table->timestamp('stats_synced_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_active', 'players_online']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
