<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A server usually belongs to several modes at once
     * (a Rust server can be both "modded" and "pve").
     */
    public function up(): void
    {
        Schema::create('game_mode_server', function (Blueprint $table) {
            $table->foreignId('game_mode_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            $table->primary(['game_mode_id', 'server_id']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_mode_server');
    }
};
