<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\Social\SocialUser;
use Illuminate\Support\Facades\DB;

/**
 * Turning a proof of identity into the account it belongs to.
 *
 * There is no registration step anywhere in this system. Whoever proves they
 * hold a mailbox or a provider account either has an account here or gets one
 * on the spot — which is why the dialog has a single screen and no "sign up".
 */
class Accounts
{
    /** The mailbox was proved by a one-time code, so the address is verified by definition. */
    public function forEmail(string $email): User
    {
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = User::nameFromEmail($email);
        }

        $user->email_verified_at ??= now();
        $user->save();

        return $user;
    }

    public function forSocial(SocialUser $profile): User
    {
        return DB::transaction(function () use ($profile) {
            $account = SocialAccount::query()
                ->where('provider', $profile->provider)
                ->where('provider_id', $profile->id)
                ->first();

            $user = $account?->user ?? $this->matchByEmail($profile) ?? $this->create($profile);

            // The profile is refreshed on every sign-in: people rename
            // themselves, and a stale persona on a server card is a complaint.
            SocialAccount::updateOrCreate(
                ['provider' => $profile->provider, 'provider_id' => $profile->id],
                [
                    'user_id' => $user->id,
                    'nickname' => $profile->nickname,
                    'avatar_url' => $profile->avatarUrl,
                    'last_login_at' => now(),
                ],
            );

            // An account created by Steam has no picture until the day its owner
            // also signs in with Google — then it gets one.
            if ($user->avatar_url === null && $profile->avatarUrl !== null) {
                $user->forceFill(['avatar_url' => $profile->avatarUrl])->save();
            }

            return $user;
        });
    }

    /**
     * The one way two providers converge on a single account.
     *
     * Only a provider-verified address counts. An unverified one would let
     * anyone who can type a victim's email at a lax provider walk into their
     * LobbyHub account — so those accounts are kept separate instead, and their
     * owner can link them later from the account page.
     */
    private function matchByEmail(SocialUser $profile): ?User
    {
        $email = $profile->trustedEmail();

        return $email === null ? null : User::where('email', $email)->first();
    }

    private function create(SocialUser $profile): User
    {
        $email = $profile->trustedEmail();

        return User::create([
            'name' => $profile->nickname ?? ($email ? User::nameFromEmail($email) : 'Player'),
            // Steam gives no address, and an unverified one is not worth the
            // unique index it would sit in.
            'email' => $email,
            'email_verified_at' => $email === null ? null : now(),
            'avatar_url' => $profile->avatarUrl,
        ]);
    }
}
