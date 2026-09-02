<?php

use App\Enums\QueryProtocol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ARK: Survival Ascended does not answer Valve A2S on any port.
 *
 * The game is a Unreal Engine 5 title with EOS/EAC-backed networking and no
 * Steam-side server registration — Steam Master lists nothing for it, and every
 * A2S_INFO the poller aimed at an ASA address timed out. The state that used to
 * be attempted through the A2S driver is now pulled from EOS matchmaking by
 * `eos:sync`, which is not per-server.
 *
 * Switching the protocol here does three things at once: DispatchServerQueries
 * stops handing ASA rows to ServerQueryManager, the Go sweeper (whose game
 * filter is `query_protocol IN ('source','minecraft')`) drops them from the A2S
 * pass, and any future addition of the EOS enum case is what the code will
 * check against rather than a slug hardcoded in three places.
 *
 * The `is_active` flag is left alone. It only decides whether the game has a
 * landing page — nothing about how it is polled — and turning ASA off here
 * would take the page down with the protocol change, which is not the goal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')
            ->where('slug', 'ark-survival-ascended')
            ->update(['query_protocol' => QueryProtocol::Eos->value]);
    }

    public function down(): void
    {
        DB::table('games')
            ->where('slug', 'ark-survival-ascended')
            ->update(['query_protocol' => QueryProtocol::Source->value]);
    }
};
