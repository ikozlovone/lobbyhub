<?php

namespace App\Services\Geo;

interface GeoResolver
{
    /** ISO 3166-1 alpha-2 code, or null when the IP cannot be placed. */
    public function countryCode(string $ip): ?string;
}
