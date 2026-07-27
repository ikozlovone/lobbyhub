<?php

namespace App\Services\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader\InvalidDatabaseException;

/**
 * Local GeoLite2-Country lookup. The reader is memory-mapped and kept open for
 * the life of the worker — cheap enough to call once per server query.
 */
class MaxMindGeoResolver implements GeoResolver
{
    private ?Reader $reader = null;

    public function __construct(private readonly string $databasePath) {}

    public function countryCode(string $ip): ?string
    {
        try {
            $reader = $this->reader ??= new Reader($this->databasePath);

            return $reader->country($ip)->country->isoCode;
        } catch (AddressNotFoundException) {
            // Private ranges and unallocated space — expected, not worth logging.
            return null;
        } catch (InvalidDatabaseException $e) {
            Log::warning('GeoLite2 database is unreadable', [
                'path' => $this->databasePath,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
