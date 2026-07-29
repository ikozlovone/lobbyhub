<?php

namespace App\Services\Auth\Social\Contracts;

use App\Services\Auth\Social\Exceptions\SocialAuthFailed;
use App\Services\Auth\Social\SocialUser;
use Illuminate\Http\Request;

interface SocialProvider
{
    /** Whether credentials for this provider are present. Missing ones hide the button. */
    public function isConfigured(): bool;

    /** Name for the button: "Steam", "Discord", "Google". */
    public function label(): string;

    /** Where to send the browser. `$state` comes back with it and is checked on return. */
    public function redirectUrl(string $state): string;

    /**
     * Read the profile out of the provider's answer.
     *
     * @throws SocialAuthFailed on anything that is not a signed-in user —
     *                          a refused consent screen, a forged callback,
     *                          a provider that is having a bad afternoon.
     */
    public function userFromCallback(Request $request): SocialUser;
}
