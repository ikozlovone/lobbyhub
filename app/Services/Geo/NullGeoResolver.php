<?php

namespace App\Services\Geo;

/**
 * Fallback when no GeoLite2 database has been downloaded — monitoring keeps
 * working, servers just stay without a location.
 */
class NullGeoResolver implements GeoResolver
{
    public function locate(string $ip): ?GeoLocation
    {
        return null;
    }
}
