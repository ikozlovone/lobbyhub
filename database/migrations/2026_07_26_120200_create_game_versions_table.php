<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            $table->string('slug', 64);                      // /games/minecraft/version/1-21
            $table->string('name', 64);                      // 1.21
            $table->date('released_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedInteger('servers_count')->default(0);
            $table->unsignedInteger('players_online')->default(0);

            $table->timestamps();

            $table->unique(['game_id', 'slug']);
            $table->index(['game_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_versions');
    }
};
