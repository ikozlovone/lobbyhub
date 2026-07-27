<?php

namespace App\Services\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader\InvalidDatabaseException;

/**
 * Local GeoLite2 lookup. The reader is memory-mapped and kept open for the life
 * of the worker — cheap enough to call once per newly discovered server.
 *
 * Works with either database. Which one is in front of us is read from the file's
 * own metadata rather than guessed from its name: calling city() on a Country
 * database throws, and a renamed file would otherwise break geo silently.
 */
class MaxMindGeoResolver implements GeoResolver
{
    private ?Reader $reader = null;

    private ?bool $hasCities = null;

    public function __construct(private readonly string $databasePath) {}

    public function locate(string $ip): ?GeoLocation
    {
        try {
            $reader = $this->reader ??= new Reader($this->databasePath);
            $this->hasCities ??= str_contains($reader->metadata()->databaseType, 'City');

            if ($this->hasCities) {
                $record = $reader->city($ip);

                return new GeoLocation(
                    countryCode: $record->country->isoCode,
                    city: $record->city->name,
                );
            }

            return new GeoLocation(countryCode: $reader->country($ip)->country->isoCode);
        } catch (AddressNotFoundException) {
            // Private ranges and unallocated space — expected, not worth logging.
            return null;
        } catch (InvalidDatabaseException $exception) {
            Log::warning('GeoLite2 database is unreadable', [
                'path' => $this->databasePath,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
