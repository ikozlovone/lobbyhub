<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Starting a session.
 *
 * The frontend runs on its own origin, so a session is a Sanctum token it
 * holds — not a cookie. Every sign-in mints a fresh one and none of them
 * outlives `auth.token_lifetime`, which is what makes signing out of one
 * browser leave the others alone.
 */
class Sessions
{
    public function start(User $user, string $device = 'web'): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->createToken(
            $device,
            ['*'],
            now()->addDays((int) config('auth.token_lifetime')),
        )->plainTextToken;
    }
}
