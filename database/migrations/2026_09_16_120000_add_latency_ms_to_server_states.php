<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the round trip somewhere to live again.
 *
 * It used to be one column of `server_stats`, a row per check — and when that
 * table was retired the "Ping" row on the server page went with it, except
 * nobody told `ServerDetailResource`, which kept calling a relation that no
 * longer existed and turned every detail read into a 500.
 *
 * What is kept here is the *latest* measurement, not a history of them: the
 * panel shows one number, and a per-sample table is exactly the write volume
 * the retirement was getting rid of. It sits on `server_states` because it is
 * a measurement the monitor rewrites, which is what that table is for.
 *
 * The Go sweeper does not write this column — its UPDATE lists the columns it
 * sets and this is not one of them, so a value written by an interactive check
 * survives the sweeps that follow it.
 *
 * ADD COLUMN on the partitioned parent cascades to every partition, and the
 * partition manager stamps new games from the parent, so both sides are
 * covered without touching either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_states', function (Blueprint $table) {
            // Milliseconds. `integer`, not the `smallint` the retired
            // `server_stats` used: the drivers cap what they report at 65535
            // and Postgres has no unsigned types, so anything past 32767 was
            // an out-of-range INSERT waiting for one slow enough server.
            $table->integer('latency_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_states', function (Blueprint $table) {
            $table->dropColumn('latency_ms');
        });
    }
};
