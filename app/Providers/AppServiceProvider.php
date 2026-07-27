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
        // Generous for a public read-only catalog, but it stops a single client
        // from turning the listing endpoints into a load test.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
