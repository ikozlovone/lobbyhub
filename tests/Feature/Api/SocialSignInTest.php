<?php

namespace Tests\Feature\Api;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\Social\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SocialSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.steam.key', 'steam-test-key');
        config()->set('services.discord', ['client_id' => 'discord-id', 'client_secret' => 'discord-secret']);
        config()->set('services.google', ['client_id' => 'google-id', 'client_secret' => 'google-secret']);
        config()->set('services.frontend.url', 'http://localhost:3000');
    }

    public function test_every_provider_is_offered_with_whether_it_is_switched_on(): void
    {
        $all = collect($this->getJson('/api/auth/providers')->assertOk()->json('data'));

        $this->assertEquals(['steam', 'discord', 'google'], $all->pluck('key')->all());
        $this->assertEquals([true, true, true], $all->pluck('available')->all());

        // Credentials decide whether a button works, not whether it is shown:
        // the dialog says what we support, and `available` says what is on.
        config()->set('services.discord.client_secret', null);
        config()->set('services.steam.key', null);

        $available = collect($this->getJson('/api/auth/providers')->json('data'))
            ->pluck('available', 'key');

        $this->assertFalse($available['steam']);
        $this->assertFalse($available['discord']);
        $this->assertTrue($available['google']);
    }

    public function test_a_provider_without_credentials_refuses_to_redirect(): void
    {
        // The button for it is dimmed, but the URL is public and guessable, and
        // following it must not hand the visitor to Discord without a client id.
        config()->set('services.discord.client_secret', null);

        $this->get('/api/auth/discord/redirect')
            ->assertRedirect()
            ->assertRedirectContains('error=');
    }

    public function test_the_redirect_sends_the_visitor_to_the_provider_with_a_state(): void
    {
        $response = $this->get('/api/auth/discord/redirect')->assertRedirect();
        $target = $response->headers->get('Location');

        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $target);
        parse_str(parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('discord-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('identify email', $query['scope']);
        $this->assertSame(route('api.auth.social.callback', ['provider' => 'discord']), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    /**
     * OpenID refuses an assertion whose return_to sits outside the realm, and
     * APP_URL routinely omits the port the API actually answers on.
     */
    public function test_the_steam_realm_contains_the_address_it_returns_to(): void
    {
        config()->set('app.url', 'http://localhost');

        $target = $this->get('/api/auth/steam/redirect')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($target, PHP_URL_QUERY), $query);

        // parse_str turns the dots in OpenID's parameter names into underscores.
        $this->assertStringStartsWith($query['openid_realm'], $query['openid_return_to']);
    }

    public function test_an_unknown_provider_bounces_back_with_an_error(): void
    {
        $this->get('/api/auth/myspace/redirect')
            ->assertRedirect('http://localhost:3000/auth/callback#error=That+sign-in+method+is+not+available.');
    }

    public function test_discord_signs_a_new_visitor_in_and_creates_the_account(): void
    {
        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['access_token' => 'at_1']),
            'discord.com/api/users/@me' => Http::response([
                'id' => '4455',
                'global_name' => 'Nomad',
                'username' => 'nomad_1990',
                'email' => 'nomad@example.com',
                'verified' => true,
                'avatar' => 'abc123',
            ]),
        ]);

        $token = $this->tokenFrom($this->hitCallback('discord', ['code' => 'auth-code']));

        $user = User::firstOrFail();
        $this->assertSame('Nomad', $user->name);
        $this->assertSame('nomad@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('https://cdn.discordapp.com/avatars/4455/abc123.png', $user->avatar_url);

        $account = SocialAccount::firstOrFail();
        $this->assertSame('discord', $account->provider);
        $this->assertSame('4455', $account->provider_id);
        $this->assertTrue($account->user->is($user));

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Nomad')
            ->assertJsonPath('data.providers', ['discord']);
    }

    public function test_steam_signs_in_without_an_email_at_all(): void
    {
        Http::fake([
            'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
            'api.steampowered.com/*' => Http::response(['response' => ['players' => [[
                'steamid' => '76561198000000001',
                'personaname' => 'Bandit',
                'avatarfull' => 'https://avatars.steamstatic.com/full.jpg',
            ]]]]),
        ]);

        $this->tokenFrom($this->steamCallback('76561198000000001'));

        $user = User::firstOrFail();
        $this->assertSame('Bandit', $user->name);
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('76561198000000001', SocialAccount::firstOrFail()->provider_id);
    }

    public function test_a_forged_steam_assertion_is_refused(): void
    {
        // Steam says the signature does not check out — the parameters are
        // plain query string, so this is the whole defence.
        Http::fake([
            'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:false\n"),
        ]);

        $this->steamCallback('76561198000000002')
            ->assertRedirectContains('error=');

        $this->assertSame(0, User::count());
    }

    public function test_a_callback_without_a_matching_state_is_refused(): void
    {
        Http::fake();

        $this->get('/api/auth/discord/callback?code=auth-code&state=made-up')
            ->assertRedirectContains('error=That+sign-in+link+has+expired');

        $this->assertSame(0, User::count());
        Http::assertNothingSent();
    }

    public function test_a_state_minted_for_one_provider_does_not_open_another(): void
    {
        Http::fake();

        $state = app(OAuthState::class)->issue('discord');

        $this->get("/api/auth/google/callback?code=auth-code&state={$state}")
            ->assertRedirectContains('error=');

        $this->assertSame(0, User::count());
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['access_token' => 'at_1']),
            'discord.com/api/users/@me' => Http::response(['id' => '1', 'username' => 'once', 'verified' => false]),
        ]);

        $state = app(OAuthState::class)->issue('discord');

        $this->get("/api/auth/discord/callback?code=c&state={$state}")->assertRedirectContains('token=');
        $this->get("/api/auth/discord/callback?code=c&state={$state}")->assertRedirectContains('error=');

        $this->assertSame(1, User::count());
    }

    public function test_a_declined_consent_screen_comes_back_as_a_message_not_an_account(): void
    {
        Http::fake();

        $this->hitCallback('discord', ['error' => 'access_denied'])
            ->assertRedirectContains('error=Sign-in+with+Discord+was+cancelled.');

        $this->assertSame(0, User::count());
    }

    public function test_signing_in_twice_with_the_same_provider_reuses_the_account(): void
    {
        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['access_token' => 'at_1']),
            'discord.com/api/users/@me' => Http::sequence()
                ->push(['id' => '99', 'global_name' => 'Old Name', 'verified' => false])
                ->push(['id' => '99', 'global_name' => 'New Name', 'verified' => false]),
        ]);

        $this->hitCallback('discord', ['code' => 'a'])->assertRedirectContains('token=');
        $this->hitCallback('discord', ['code' => 'b'])->assertRedirectContains('token=');

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
        // The persona is refreshed on every sign-in; the account is not remade.
        $this->assertSame('New Name', SocialAccount::firstOrFail()->nickname);
    }

    public function test_a_provider_verified_address_joins_the_account_that_already_uses_it(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.com']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at_1']),
            'openidconnect.googleapis.com/*' => Http::response([
                'sub' => 'google-1',
                'name' => 'Owner',
                'email' => 'owner@example.com',
                'email_verified' => true,
            ]),
        ]);

        $this->hitCallback('google', ['code' => 'a'])->assertRedirectContains('token=');

        $this->assertSame(1, User::count());
        $this->assertTrue(SocialAccount::firstOrFail()->user->is($existing));
    }

    /**
     * The other half of that rule, and the reason it is a rule: a provider that
     * does not verify addresses would otherwise hand over any account whose
     * email an attacker can type.
     */
    public function test_an_unverified_address_does_not_claim_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.com']);

        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['access_token' => 'at_1']),
            'discord.com/api/users/@me' => Http::response([
                'id' => 'impostor',
                'username' => 'impostor',
                'email' => 'owner@example.com',
                'verified' => false,
            ]),
        ]);

        $this->hitCallback('discord', ['code' => 'a'])->assertRedirectContains('token=');

        $this->assertSame(2, User::count());
        $this->assertFalse(SocialAccount::firstOrFail()->user->is($existing));
        $this->assertNull(SocialAccount::firstOrFail()->user->email);
    }

    /** @param  array<string, string>  $query */
    private function hitCallback(string $provider, array $query): TestResponse
    {
        $state = app(OAuthState::class)->issue($provider);

        return $this->get("/api/auth/{$provider}/callback?".http_build_query($query + ['state' => $state]));
    }

    private function steamCallback(string $steamId): TestResponse
    {
        return $this->hitCallback('steam', [
            'openid.mode' => 'id_res',
            'openid.claimed_id' => "https://steamcommunity.com/openid/id/{$steamId}",
            'openid.identity' => "https://steamcommunity.com/openid/id/{$steamId}",
            'openid.sig' => 'signature',
        ]);
    }

    /** Pulls the token out of the fragment the callback redirects to. */
    private function tokenFrom(TestResponse $response): string
    {
        parse_str(parse_url((string) $response->headers->get('Location'), PHP_URL_FRAGMENT) ?? '', $fragment);

        $this->assertArrayHasKey('token', $fragment, 'the callback did not sign anybody in');

        return $fragment['token'];
    }
}
