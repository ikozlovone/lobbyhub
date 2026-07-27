<?php

namespace App\Services\Geo;

interface GeoResolver
{
    /** Null when the IP cannot be placed at all — private ranges, anycast, gaps. */
    public function locate(string $ip): ?GeoLocation;
}
