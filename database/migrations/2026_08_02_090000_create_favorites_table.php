<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Servers somebody wants to find again.
     *
     * A join table with one useful column of its own — when it was starred, which
     * is the order the list is read in. No `updated_at`: a favourite is not
     * edited, it exists or it does not.
     *
     * Both sides cascade. An account that is deleted takes its list with it, and
     * a server dropped from the catalog should not leave rows pointing at a page
     * that 404s.
     */
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // Starring twice is the same star, and the star button will happily
            // send the same request twice on a double click.
            $table->unique(['user_id', 'server_id']);

            // The one query this table exists for: everything one account starred.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
