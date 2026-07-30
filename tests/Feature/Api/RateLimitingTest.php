<?php

namespace Tests\Feature\Api;

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    public function test_the_read_budget_is_per_visitor(): void
    {
        $limit = $this->limitFor('203.0.113.9');

        $this->assertSame(600, $limit->maxAttempts);
        $this->assertSame('203.0.113.9', $limit->key);
    }

    /**
     * The frontend renders on this machine and reads the API over loopback, so
     * every server-rendered page in the site shares one address. Counted as a
     * visitor, a single click in the games menu — which prefetches every link on
     * the page it opens — can spend the budget for everyone at once, and a 429
     * inside a render aborts a stream the browser is already reading.
     */
    public function test_the_sites_own_renders_are_not_counted_as_a_visitor(): void
    {
        $this->assertInstanceOf(Unlimited::class, $this->limitFor('127.0.0.1'));
        $this->assertInstanceOf(Unlimited::class, $this->limitFor('::1'));
    }

    private function limitFor(string $ip): mixed
    {
        $limiter = RateLimiter::limiter('api');

        return $limiter(Request::create('/api/games', server: ['REMOTE_ADDR' => $ip]));
    }
}
