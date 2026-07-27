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
        // Geo lookups degrade to a no-op until the GeoLite2 file is in place.
        $this->app->singleton(GeoResolver::class, function () {
            $path = config('monitoring.geoip.database');

            return is_string($path) && is_file($path)
                ? new MaxMindGeoResolver($path)
                : new NullGeoResolver;
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
