<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider identities, one row per (provider, account).
     *
     * A separate table rather than columns on `users` because one person is
     * routinely all three: they sign in with Steam on the desktop, with Google
     * on a phone, and gave us an email months earlier. Those have to converge
     * on one account instead of forking into three.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32);       // steam | discord | google

            // Provider ids are opaque strings — a Steam id is 17 digits and
            // overflows a JS number, a Google `sub` is not numeric at all.
            $table->string('provider_id', 64);

            $table->string('nickname')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            // The identity itself: one provider account belongs to one user.
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
