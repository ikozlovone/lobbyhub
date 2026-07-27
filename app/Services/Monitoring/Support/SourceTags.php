<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Carbon;

/**
 * Rust publishes its real numbers in the A2S keyword list, and the same string
 * comes back from the Steam Web API as `gametype`. Both paths need it parsed
 * the same way, so the logic lives here rather than inside one driver.
 *
 *   cp/mp — current and max players. A2S gives each a single byte, so a
 *           300-slot server cannot describe itself there; the API's own
 *           `players` field disagrees with `cp` on 17% of servers as well.
 *   qp    — players waiting in the join queue.
 *   born  — unix time the server last started, in practice the last wipe.
 *
 * Other games send different tags. These patterns are narrow enough not to
 * match them, so everything stays null and the caller keeps its own values.
 */
final class SourceTags
{
    /**
     * @return array{players: ?int, max_players: ?int, queued: ?int, wiped_at: ?Carbon}
     */
    public static function parse(?string $keywords): array
    {
        $parsed = ['players' => null, 'max_players' => null, 'queued' => null, 'wiped_at' => null];

        if ($keywords === null || $keywords === '') {
            return $parsed;
        }

        foreach (explode(',', $keywords) as $tag) {
            match (true) {
                (bool) preg_match('/^cp(\d+)$/', $tag, $m) => $parsed['players'] = (int) $m[1],
                (bool) preg_match('/^mp(\d+)$/', $tag, $m) => $parsed['max_players'] = (int) $m[1],
                (bool) preg_match('/^qp(\d+)$/', $tag, $m) => $parsed['queued'] = min((int) $m[1], 65535),
                (bool) preg_match('/^born(\d+)$/', $tag, $m) => $parsed['wiped_at'] = self::timestamp((int) $m[1]),
                default => null,
            };
        }

        return $parsed;
    }

    /** Reject nonsense timestamps rather than storing them. */
    private static function timestamp(int $unix): ?Carbon
    {
        if ($unix < 1_400_000_000 || $unix > now()->addDay()->timestamp) {
            return null;
        }

        return Carbon::createFromTimestamp($unix);
    }
}
