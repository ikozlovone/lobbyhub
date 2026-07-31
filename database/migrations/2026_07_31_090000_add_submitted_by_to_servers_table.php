<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            /**
             * Who put this server in the catalog through the form.
             *
             * Deliberately not `user_id`: that one means the owner has proved
             * the server is theirs, which is a claim we act on. Submitting is
             * only ever "somebody typed an address", and the two must not be
             * confused — anyone can submit anyone's server, which is the whole
             * reason the address is verified rather than trusted.
             *
             * Nullable because the form takes submissions from visitors who are
             * not signed in, and because everything already listed came from
             * discovery, which has no submitter at all.
             */
            $table->foreignId('submitted_by_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
        });
    }
};
