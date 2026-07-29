<?php

namespace App\Services\Auth\Social;

use App\Services\Auth\Social\Contracts\SocialProvider;
use App\Services\Auth\Social\Exceptions\SocialAuthFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The half of OAuth 2 that Discord and Google do identically: send the browser
 * to a consent screen, trade the returned code for a token, read one profile
 * endpoint with it.
 *
 * Written out rather than pulled in, for the same reason the A2S and Minecraft
 * drivers are: this is three requests against a documented contract, and the
 * shape it fits — a contract, a driver per service, a manager that picks one —
 * is already how monitoring is built.
 *
 * CSRF protection is the `state` parameter, minted and checked by OAuthState.
 * PKCE is deliberately absent: it protects a public client that cannot hold a
 * secret, and this exchange happens server-side with one.
 */
abstract class OAuth2Provider implements SocialProvider
{
    public function __construct(protected readonly string $key) {}

    abstract protected function authorizeUrl(): string;

    abstract protected function tokenUrl(): string;

    abstract protected function profileUrl(): string;

    /** @return list<string> */
    abstract protected function scopes(): array;

    /** @param  array<string, mixed>  $profile */
    abstract protected function mapUser(array $profile): SocialUser;

    public function isConfigured(): bool
    {
        return $this->config('client_id') !== null && $this->config('client_secret') !== null;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function redirectUrl(string $state): string
    {
        return $this->authorizeUrl().'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ]);
    }

    public function userFromCallback(Request $request): SocialUser
    {
        if ($request->filled('error') || ! $request->filled('code')) {
            throw SocialAuthFailed::declined($this->label());
        }

        return $this->mapUser($this->profile($this->accessToken((string) $request->query('code'))));
    }

    protected function accessToken(string $code): string
    {
        $response = $this->post($this->tokenUrl(), [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->callbackUrl(),
        ]);

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialAuthFailed::rejected($this->label(), 'no access token in the response');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    protected function profile(string $token): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->get($this->profileUrl());
        } catch (ConnectionException) {
            throw SocialAuthFailed::unreachable($this->label());
        }

        if ($response->failed()) {
            throw SocialAuthFailed::rejected($this->label(), "profile request returned HTTP {$response->status()}");
        }

        $profile = $response->json();

        return is_array($profile) ? $profile : throw SocialAuthFailed::rejected($this->label(), 'unreadable profile');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $url, array $payload): array
    {
        try {
            $response = Http::timeout(10)->asForm()->acceptJson()->post($url, $payload);
        } catch (ConnectionException) {
            throw SocialAuthFailed::unreachable($this->label());
        }

        if ($response->failed()) {
            throw SocialAuthFailed::rejected($this->label(), "token exchange returned HTTP {$response->status()}");
        }

        $body = $response->json();

        return is_array($body) ? $body : throw SocialAuthFailed::rejected($this->label(), 'unreadable token response');
    }

    protected function callbackUrl(): string
    {
        return route('api.auth.social.callback', ['provider' => $this->key]);
    }

    protected function config(string $name): ?string
    {
        $value = config("services.{$this->key}.{$name}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
