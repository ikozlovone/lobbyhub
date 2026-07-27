<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();               // ISO 3166-1 alpha-2
            $table->string('name');
            $table->string('slug')->unique();                // /games/minecraft/country/germany
            $table->string('continent', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('servers_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
