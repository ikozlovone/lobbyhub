<?php

namespace App\Services\Discovery;

use App\Services\Monitoring\Support\SourceTags;
use Illuminate\Support\Carbon;

/**
 * One row of the Steam server list, normalized onto our own vocabulary.
 *
 * The API hands back two different addresses and it matters which is which:
 *   `addr`     — ip:port the server answers queries on  → host + query_port
 *   `gameport` — the port a player connects to          → game_port
 */
final readonly class DiscoveredServer
{
    public function __construct(
        public string $ip,
        public int $queryPort,
        public int $gamePort,
        public string $name,
        public int $playersOnline,
        public int $playersMax,
        public ?string $map,
        public ?string $version,
        public ?Carbon $wipedAt,
        public ?int $playersQueued,
        /*
         * The three that used to be dropped on the floor.
         *
         * All of them ride along in the same response — `steamid`, `bots`,
         * `secure` — and every one of them was being paid for a second time
         * with a UDP packet per server. A2S returns the Steam id in its extra
         * data block and the bot count and anti-cheat flag in the info reply;
         * nothing about them is more true for having been asked directly.
         */
        public ?string $steamId = null,
        public ?int $bots = null,
        public ?bool $vacEnabled = null,
    ) {}

    public static function fromApi(array $row): ?self
    {
        $address = (string) ($row['addr'] ?? '');

        if (! str_contains($address, ':')) {
            return null;
        }

        [$ip, $queryPort] = explode(':', $address, 2);

        if (! filter_var($ip, FILTER_VALIDATE_IP) || ! ctype_digit($queryPort)) {
            return null;
        }

        // The same tag string A2S returns as `keywords`. Its counts are more
        // reliable than the API's own `players` field, which disagrees with the
        // `cp` tag on roughly one server in six.
        $tags = SourceTags::parse($row['gametype'] ?? null);

        $name = trim((string) ($row['name'] ?? ''));

        return new self(
            ip: $ip,
            queryPort: (int) $queryPort,
            gamePort: (int) ($row['gameport'] ?? $queryPort),
            name: $name === '' ? $address : mb_substr($name, 0, 255),
            playersOnline: max(0, $tags['players'] ?? (int) ($row['players'] ?? 0)),
            playersMax: max(0, $tags['max_players'] ?? (int) ($row['max_players'] ?? 0)),
            map: self::clean($row['map'] ?? null),
            version: self::clean($row['version'] ?? null),
            wipedAt: $tags['wiped_at'],
            playersQueued: $tags['queued'],
            steamId: self::clean($row['steamid'] ?? null),
            bots: isset($row['bots']) ? max(0, (int) $row['bots']) : null,
            vacEnabled: isset($row['secure']) ? (bool) $row['secure'] : null,
        );
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
