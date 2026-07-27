<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // MOTD comes free with every status response and belongs on the card;
            // `description` stays the owner-written text.
            $table->text('motd')->nullable()->after('description');

            // When this server is due for its next query. Backoff is stored here
            // rather than derived in the dispatcher query, so it stays indexable.
            $table->timestamp('next_query_at')->useCurrent()->after('last_online_at');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->index(['is_active', 'next_query_at']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_query_at']);
            $table->dropColumn(['motd', 'next_query_at']);
        });
    }
};
