<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Accounts;
use App\Services\Auth\Sessions;
use App\Services\Auth\Social\Exceptions\SocialAuthFailed;
use App\Services\Auth\Social\OAuthState;
use App\Services\Auth\Social\SocialProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign in with Steam, Discord or Google.
 *
 * Both endpoints are browser navigations, not API calls: the visitor leaves for
 * the provider and comes back. The token is handed over in the URL **fragment**
 * of the return trip, which never reaches a server, a proxy log or a Referer
 * header — the frontend reads it and clears it from the address bar.
 */
class SocialAuthController extends Controller
{
    public function redirect(
        Request $request,
        string $provider,
        SocialProviders $providers,
        OAuthState $state,
    ): RedirectResponse {
        $driver = $providers->for($provider);

        if ($driver === null) {
            return $this->back(['error' => 'That sign-in method is not available.']);
        }

        return redirect()->away($driver->redirectUrl($state->issue($provider)));
    }

    public function callback(
        Request $request,
        string $provider,
        SocialProviders $providers,
        OAuthState $state,
        Accounts $accounts,
        Sessions $sessions,
    ): RedirectResponse {
        $driver = $providers->for($provider);

        if ($driver === null) {
            return $this->back(['error' => 'That sign-in method is not available.']);
        }

        // Checked before anything is exchanged: a callback we did not start is
        // how a visitor gets signed into an attacker's account.
        if ($state->consume($request->query('state'), $provider) === null) {
            return $this->back(['error' => 'That sign-in link has expired. Try again.']);
        }

        try {
            $profile = $driver->userFromCallback($request);
        } catch (SocialAuthFailed $failure) {
            return $this->back(['error' => $failure->getMessage()]);
        }

        $user = $accounts->forSocial($profile);

        return $this->back(['token' => $sessions->start($user, $provider)]);
    }

    /**
     * Back to the frontend, with the outcome in the fragment.
     *
     * @param  array<string, string>  $result
     */
    private function back(array $result): RedirectResponse
    {
        $frontend = rtrim((string) config('services.frontend.url'), '/');

        return redirect()->away("{$frontend}/auth/callback#".http_build_query($result));
    }
}
