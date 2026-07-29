<?php

namespace Tests\Feature\Api;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_first_time_visitor_is_signed_in_and_given_an_account(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'New.Player@example.com'])
            ->assertAccepted()
            // Normalised: the same mailbox typed two ways is one account.
            ->assertJsonPath('data.email', 'new.player@example.com');

        Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('new.player@example.com'));

        $this->assertSame(0, User::count(), 'the account must not exist before the code is proved');

        $response = $this->postJson('/api/auth/email/verify', [
            'email' => 'new.player@example.com',
            'code' => $this->sentCode(),
        ])->assertCreated();

        $user = User::firstOrFail();

        $this->assertSame('new.player@example.com', $user->email);
        $this->assertSame('New Player', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertNotNull($user->last_login_at);

        $this->assertSame($user->name, $response->json('data.user.name'));
        $this->assertNotEmpty($response->json('data.token'));

        // The code is single use.
        $this->assertSame(0, LoginCode::count());
    }

    public function test_a_returning_visitor_reaches_the_same_account(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->postJson('/api/auth/email', ['email' => 'owner@example.com'])->assertAccepted();
        $this->postJson('/api/auth/email/verify', [
            'email' => 'owner@example.com',
            'code' => $this->sentCode(),
        ])->assertCreated()->assertJsonPath('data.user.id', $user->id);

        $this->assertSame(1, User::count());
    }

    public function test_the_token_it_returns_authenticates_the_account(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com']);
        $token = $this->postJson('/api/auth/email/verify', [
            'email' => 'player@example.com',
            'code' => $this->sentCode(),
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'player@example.com');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Signing out kills that token and nothing else.
        $this->nextRequest()->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_signing_out_of_one_browser_leaves_the_others_signed_in(): void
    {
        $user = User::factory()->create();
        $laptop = $user->createToken('web')->plainTextToken;
        $phone = $user->createToken('web')->plainTextToken;

        $this->withToken($laptop)->postJson('/api/auth/logout')->assertOk();

        $this->nextRequest()->withToken($laptop)->getJson('/api/auth/me')->assertUnauthorized();
        $this->nextRequest()->withToken($phone)->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com']);

        $this->postJson('/api/auth/email/verify', ['email' => 'player@example.com', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, LoginCode::firstOrFail()->attempts);
        $this->assertSame(0, User::count());
    }

    public function test_guessing_stops_after_the_attempt_budget_even_with_the_right_code(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com']);
        $code = $this->sentCode();

        foreach (range(1, (int) config('auth.codes.attempts')) as $attempt) {
            $this->postJson('/api/auth/email/verify', ['email' => 'player@example.com', 'code' => '000001'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/email/verify', ['email' => 'player@example.com', 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(0, User::count());
        // A burnt code is cleared, so the next attempt starts from a new email.
        $this->assertSame(0, LoginCode::count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com']);
        $code = $this->sentCode();

        $this->travel((int) config('auth.codes.ttl') + 1)->seconds();

        $this->postJson('/api/auth/email/verify', ['email' => 'player@example.com', 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(0, User::count());
    }

    public function test_a_second_code_replaces_the_first_rather_than_joining_it(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com'])->assertAccepted();
        $first = $this->sentCode();

        $this->travel((int) config('auth.codes.cooldown') + 1)->seconds();

        $this->postJson('/api/auth/email', ['email' => 'player@example.com'])->assertAccepted();

        $this->assertSame(1, LoginCode::count());
        $this->assertFalse(Hash::check($first, LoginCode::firstOrFail()->code_hash));
    }

    public function test_asking_again_immediately_is_refused_with_a_wait(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'player@example.com'])->assertAccepted();

        $this->postJson('/api/auth/email', ['email' => 'player@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertSentCount(1);
    }

    public function test_an_unparseable_address_never_reaches_the_mailer(): void
    {
        $this->postJson('/api/auth/email', ['email' => 'not an address'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_the_account_endpoint_needs_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    /**
     * Start the next request with no guard state carried over.
     *
     * Every request in a test shares one application instance, so a guard that
     * resolved a user on the previous call still holds them on the next one.
     * In production each request is its own process; without this, a test can
     * only ever prove that a token worked once.
     */
    private function nextRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** The code only ever exists in the mail — which is the point of hashing it. */
    private function sentCode(): string
    {
        $codes = [];

        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$codes) {
            $codes[] = $mail->code;

            return true;
        });

        return end($codes) ?: '';
    }
}
