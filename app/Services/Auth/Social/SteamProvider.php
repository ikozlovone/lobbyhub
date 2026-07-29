<?php

namespace App\Services\Auth\Social;

use App\Services\Auth\Social\Contracts\SocialProvider;
use App\Services\Auth\Social\Exceptions\SocialAuthFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Steam sign-in — OpenID 2.0, not OAuth.
 *
 * Steam never adopted OAuth for player sign-in, so this is the older protocol
 * and it works differently in two ways that matter:
 *
 *  - There is no client secret and no token exchange. Steam redirects back with
 *    a signed assertion, and we ask Steam itself to confirm it (`check_authentication`).
 *    Skipping that call is the classic Steam-login hole: the parameters are
 *    plain query string and anyone can type a claimed_id.
 *  - There is no email, ever. That is why `users.email` is nullable — an account
 *    can be a Steam persona and nothing else.
 *
 * There is also no `state` parameter in the spec. The nonce rides inside
 * `openid.return_to`, which Steam echoes back and includes in what it signs.
 */
class SteamProvider implements SocialProvider
{
    private const OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';

    private const SUMMARIES = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/';

    private const NS = 'http://specs.openid.net/auth/2.0';

    private const IDENTIFIER_SELECT = 'http://specs.openid.net/auth/2.0/identifier_select';

    /**
     * The handshake needs no credentials, but a signed-in Steam user with no
     * persona name is a listing entry called "Player" — so the button appears
     * only when the Web API key that fetches the profile is present. It is the
     * same key server discovery already uses.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function label(): string
    {
        return 'Steam';
    }

    public function redirectUrl(string $state): string
    {
        $callback = route('api.auth.social.callback', ['provider' => 'steam']);

        return self::OPENID_ENDPOINT.'?'.http_build_query([
            'openid.ns' => self::NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $callback.'?state='.urlencode($state),
            // Derived from the callback rather than read from APP_URL: OpenID
            // refuses an assertion whose return_to is not inside the realm, and
            // an APP_URL that omits the port — which is every local setup —
            // produces exactly that mismatch.
            'openid.realm' => $this->realmFor($callback),
            // "Whoever is signed in" — the client does not know the id yet.
            'openid.identity' => self::IDENTIFIER_SELECT,
            'openid.claimed_id' => self::IDENTIFIER_SELECT,
        ]);
    }

    public function userFromCallback(Request $request): SocialUser
    {
        if ($request->query('openid_mode') === 'cancel' || ! $request->has('openid_claimed_id')) {
            throw SocialAuthFailed::declined('Steam');
        }

        $this->verifyAssertion($request);

        $steamId = $this->steamIdFrom((string) $request->query('openid_claimed_id'));
        $profile = $this->playerSummary($steamId);

        return new SocialUser(
            provider: 'steam',
            id: $steamId,
            nickname: $this->string($profile['personaname'] ?? null),
            // Steam hands back no address at all, verified or otherwise.
            email: null,
            emailVerified: false,
            avatarUrl: $this->string($profile['avatarfull'] ?? null) ?? $this->string($profile['avatar'] ?? null),
        );
    }

    /**
     * Ask Steam whether it really signed this.
     *
     * Every `openid.*` parameter goes back untouched with the mode swapped:
     * they are what the signature covers, so dropping or normalising one turns
     * a valid assertion into an invalid one.
     */
    private function verifyAssertion(Request $request): void
    {
        $params = ['openid.mode' => 'check_authentication'];

        foreach ($request->query() as $key => $value) {
            // PHP turns dots in query keys into underscores; OpenID names them
            // with dots, and the signature is over the dotted names.
            if (str_starts_with($key, 'openid_') && $key !== 'openid_mode' && is_string($value)) {
                $params['openid.'.substr($key, 7)] = $value;
            }
        }

        try {
            $response = Http::timeout(10)->asForm()->post(self::OPENID_ENDPOINT, $params);
        } catch (ConnectionException) {
            throw SocialAuthFailed::unreachable('Steam');
        }

        if ($response->failed() || ! str_contains($response->body(), 'is_valid:true')) {
            throw SocialAuthFailed::rejected('Steam', 'the assertion did not verify');
        }
    }

    private function steamIdFrom(string $claimedId): string
    {
        // https://steamcommunity.com/openid/id/76561198000000000
        if (preg_match('#^https?://steamcommunity\.com/openid/id/(\d{17})$#', $claimedId, $matches) !== 1) {
            throw SocialAuthFailed::rejected('Steam', 'unrecognised account id');
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function playerSummary(string $steamId): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get(self::SUMMARIES, [
                'key' => $this->apiKey(),
                'steamids' => $steamId,
            ]);
        } catch (ConnectionException) {
            throw SocialAuthFailed::unreachable('Steam');
        }

        // A missing profile is not a failed sign-in: the assertion already
        // proved who this is, and the name is decoration on top of it.
        $players = $response->successful() ? $response->json('response.players') : null;

        return is_array($players) && is_array($players[0] ?? null) ? $players[0] : [];
    }

    /** The origin of a URL: scheme, host and port, nothing else. */
    private function realmFor(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$port;
    }

    private function apiKey(): ?string
    {
        $key = config('services.steam.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
