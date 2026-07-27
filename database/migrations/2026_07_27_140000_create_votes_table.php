<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            /**
             * The voter's IP, hashed with the app key.
             *
             * Anti-fraud needs to recognise a repeat voter; nothing needs the
             * address itself, and storing raw IPs of people who never signed up
             * is not something to do casually.
             */
            $table->char('ip_hash', 64);

            /**
             * The in-game name, so an owner can reward the player who voted.
             * Optional: a vote still counts without one.
             */
            $table->string('nickname', 64)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /**
             * The day the vote belongs to, stored rather than derived so the
             * one-vote-per-day rule is a unique index instead of a race between
             * a SELECT and an INSERT.
             */
            $table->date('vote_day');

            /** Set when the server has collected the reward for this vote. */
            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();

            $table->unique(['server_id', 'ip_hash', 'vote_day']);
            // Counting a server's recent votes is the hot query for ranking.
            $table->index(['server_id', 'vote_day']);
            // Owners poll "has this player voted yet" by name.
            $table->index(['server_id', 'nickname', 'rewarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
