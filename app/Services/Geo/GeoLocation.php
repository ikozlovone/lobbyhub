<?php

namespace App\Services\Geo;

final readonly class GeoLocation
{
    public function __construct(
        /** ISO 3166-1 alpha-2. */
        public ?string $countryCode,
        /** Only ever present with the City database. */
        public ?string $city = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->countryCode === null && $this->city === null;
    }
}
