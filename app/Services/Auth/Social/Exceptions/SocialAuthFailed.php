<?php

namespace App\Services\Auth\Social\Exceptions;

use RuntimeException;

/**
 * The provider did not hand us a signed-in user.
 *
 * Routine rather than exceptional: people close the consent screen. The
 * callback turns this into a redirect back to the dialog with a reason, never
 * into a stack trace.
 */
class SocialAuthFailed extends RuntimeException
{
    public static function declined(string $provider): self
    {
        return new self("Sign-in with {$provider} was cancelled.");
    }

    public static function unreachable(string $provider): self
    {
        return new self("{$provider} did not respond. Try again in a moment.");
    }

    public static function rejected(string $provider, string $reason): self
    {
        return new self("{$provider} rejected the sign-in: {$reason}");
    }
}
