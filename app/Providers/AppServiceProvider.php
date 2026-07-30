<?php

namespace App\Providers;

use App\Services\Geo\GeoResolver;
use App\Services\Geo\MaxMindGeoResolver;
use App\Services\Geo\NullGeoResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Geo lookups degrade to a no-op until a GeoLite2 file is in place.
        // City is preferred and also carries country data; Country is the fallback.
        $this->app->singleton(GeoResolver::class, function () {
            foreach ((array) config('monitoring.geoip.databases', []) as $path) {
                if (is_string($path) && is_file($path)) {
                    return new MaxMindGeoResolver($path);
                }
            }

            return new NullGeoResolver;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Every absolute URL this app hands out is built from APP_URL, not from
         * the address the request arrived on.
         *
         * Laravel derives them from the request root by default, which is right
         * exactly once: when the only way in is the public hostname. It is not
         * here. The frontend reads this API over loopback to keep its renders off
         * the network, artisan runs with no request at all, and a health check
         * arrives as 127.0.0.1 — and a "Sign in with Steam" button built during
         * any of those points the visitor's browser at their own machine.
         *
         * The scheme has to be forced alongside the root: forceRootUrl keeps the
         * host but takes the scheme from the request, and a loopback request is
         * plain HTTP however the site is served.
         *
         * Not in local development, where the request is the better source: there
         * is only one way in, and APP_URL habitually omits the port that `artisan
         * serve` is actually listening on.
         */
        if (! $this->app->environment('local') && is_string($root = config('app.url')) && $root !== '') {
            URL::forceRootUrl($root);

            if (str_starts_with($root, 'https://')) {
                URL::forceScheme('https');
            }
        }

        /*
         * Generous for a public read-only catalog, but it stops a single client
         * from turning the listing endpoints into a load test.
         *
         * A page view is not one request: the shell fetches the game, its
         * listing and its recent votes, and the live layer then re-reads player
         * counts for as long as the tab is open. The frontend build is heavier
         * still — it prerenders every game and reads this API from one address
         * as fast as it can. A budget tuned to one-request-per-view fails both,
         * and the writes worth rationing (votes, submissions, sign-in codes)
         * have their own much tighter limiters below.
         */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)
            ->by($request->user()?->id ?: $request->ip()));

        // Votes are the one public write, so they get their own, much tighter
        // budget: the daily unique index stops repeat votes for one server, this
        // stops a script walking the whole catalog.
        RateLimiter::for('votes', fn (Request $request) => Limit::perHour(30)->by($request->ip()));

        // Every submission makes us query an address the submitter chose, so
        // the budget is small enough that the form can never be used to point
        // our monitor at somebody else's network in bulk.
        RateLimiter::for('submissions', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // The refresh button. Also makes us query a real machine, but a listed
        // one rather than an address a stranger typed, and there is a per-server
        // cooldown behind it — this only stops one client walking the catalog.
        RateLimiter::for('refreshes', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Sending mail on demand to an address a stranger typed. Limited per
        // address as well as per network, so one client cannot walk a list of
        // mailboxes, and one mailbox cannot be flooded from many clients.
        RateLimiter::for('auth-codes', fn (Request $request) => [
            Limit::perHour(5)->by((string) $request->input('email')),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // Guessing budget across addresses. The per-code attempt counter is the
        // real defence; this stops the same client trying a thousand codes
        // against a thousand addresses.
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
