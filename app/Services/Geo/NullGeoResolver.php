<?php

namespace App\Services\Geo;

/**
 * Fallback when the GeoLite2 database has not been downloaded — monitoring
 * keeps working, servers just stay without a country.
 */
class NullGeoResolver implements GeoResolver
{
    public function countryCode(string $ip): ?string
    {
        return null;
    }
}
