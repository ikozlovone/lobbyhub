<?php

namespace App\Providers;

use App\Services\Geo\GeoResolver;
use App\Services\Geo\MaxMindGeoResolver;
use App\Services\Geo\NullGeoResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // The refresh button. Also makes us query a real machine, but a listed
        // one rather than an address a stranger typed, and there is a per-server
        // cooldown behind it — this only stops one client walking the catalog.
        RateLimiter::for('refreshes', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
    }
}
