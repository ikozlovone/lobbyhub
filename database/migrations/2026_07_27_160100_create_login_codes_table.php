<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes for email sign-in.
     *
     * Keyed by address rather than by an id, the same way `password_reset_tokens`
     * is: one live code per mailbox is the rule, and making it the primary key
     * means requesting a second code replaces the first instead of leaving two
     * valid ones behind.
     */
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->string('email')->primary();

            // Hashed, never stored in the clear: this table is as good as a
            // password file for the minutes a code is alive.
            $table->string('code_hash');

            $table->timestamp('expires_at');

            // Guessing budget. Six digits is a million combinations, but a code
            // that stays open for ten minutes deserves a hard ceiling anyway.
            $table->unsignedTinyInteger('attempts')->default(0);

            // Who asked, for abuse investigation — not for rate limiting, which
            // happens before a row is written.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            // Sweeping expired codes.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
