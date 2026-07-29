<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts here are proved, not typed.
     *
     * Signing in means holding a mailbox or a provider account, so two columns
     * of the framework's default shape stop being true:
     *
     *  - `password` is never set. Nothing to forget, leak or reset.
     *  - `email` can be absent: Steam OpenID hands back a persona and an id and
     *    no address at all, and refusing that account would be refusing the
     *    provider our own catalog is discovered through.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();

            $table->string('avatar_url')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'last_login_at']);
        });

        // Left nullable on the way down: rows created without either column
        // cannot be made to satisfy a NOT NULL constraint retroactively.
    }
};
