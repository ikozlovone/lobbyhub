<?php

namespace App\Services\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and checking the six digits that are the whole of email sign-in.
 *
 * The code is stored hashed and never leaves the process in the clear except
 * inside the mail it was generated for. That is not ceremony: for the ten
 * minutes it lives, this table is a password file.
 */
class LoginCodes
{
    /** @throws ValidationException when a code was issued moments ago */
    public function issue(string $email, ?string $ip = null): void
    {
        $existing = LoginCode::find($email);
        $cooldown = (int) config('auth.codes.cooldown');

        if ($existing !== null && $existing->created_at?->addSeconds($cooldown)->isFuture()) {
            $wait = (int) ceil(now()->diffInSeconds($existing->created_at->addSeconds($cooldown), absolute: true));

            throw ValidationException::withMessages([
                'email' => "A code is already on its way. Ask for another in {$wait} seconds.",
            ]);
        }

        $code = $this->generate();

        // Keyed by address, so a second request replaces the first rather than
        // leaving two working codes in one inbox.
        LoginCode::updateOrCreate(['email' => $email], [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds((int) config('auth.codes.ttl')),
            'attempts' => 0,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);

        Mail::to($email)->send(new LoginCodeMail($code));
    }

    /**
     * Check a code and consume it.
     *
     * Every failure path returns the same message on purpose: telling a caller
     * whether an address has a live code apart from whether the digits were
     * wrong turns this endpoint into a way to enumerate who has signed in.
     *
     * @throws ValidationException
     */
    public function consume(string $email, string $code): void
    {
        $record = LoginCode::find($email);

        if ($record === null || $record->isExpired() || $record->attempts >= (int) config('auth.codes.attempts')) {
            $record?->delete();

            $this->reject();
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            $this->reject();
        }

        $record->delete();
    }

    private function generate(): string
    {
        $length = (int) config('auth.codes.length');

        // Leading zeros are part of the code: padding, not arithmetic.
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'code' => 'That code is wrong or has expired. Ask for a new one.',
        ]);
    }
}
