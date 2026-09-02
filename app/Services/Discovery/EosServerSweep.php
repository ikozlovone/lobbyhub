<?php

namespace App\Services\Discovery;

use App\Models\Game;

/**
 * Every session EOS is currently listing for one deployment.
 *
 * Unlike the Steam sweep this does not slice the population by axes: EOS's
 * matchmaking answers `pagination.totalCount` alongside every page, so the
 * walk knows when to stop without asking a filter tree. ASA has around six
 * thousand live sessions on a busy evening, which is thirty pages at the
 * default 200/page — cheap enough that a whole-population read is one call
 * chain of thirty requests, not a tree of hundreds.
 *
 * If a title outgrows this and the response comes back truncated on `criteria:
 * []`, adding axis slicing over `attributes.OFFICIALSERVER_s` (`"0"` / `"1"`)
 * and region attributes is the same shape SteamServerSweep uses. That work
 * belongs when it is needed, not before.
 *
 * `$onServer` is called once per distinct address (`ip:port`), as it arrives.
 * A session that appears twice in a walk — the same server matching more than
 * one region attribute if criteria are added later — is handed to the caller
 * once. Deduplication belongs above the callback so the sync's own
 * write-once-per-id rule is not doing two jobs.
 */
class EosServerSweep
{
    public function __construct(private readonly EosClient $client) {}

    /**
     * Walk the whole deployment, calling $onServer per distinct session.
     *
     * `$only` is the same shape SteamCatalogSync passes down: a set of
     * `ip:port` keys the catalog is willing to write for. Null takes
     * everything; a set drops rows whose address is not in it before a
     * DiscoveredEosServer is built from them, so a frozen catalog does not
     * pay to parse rows it will not keep.
     *
     * @param  callable(DiscoveredEosServer): void  $onServer
     * @param  array<string, mixed>|null  $only  addresses (`ip:port`) worth
     *                                           building; null takes every row
     */
    public function stream(Game $game, callable $onServer, ?int $maxPages = null, ?array $only = null): EosSweepResult
    {
        $deployment = EosDeployment::forGame($game);

        $seen = [];
        $skipped = 0;
        $pages = 0;
        $found = 0;
        $httpStarted = hrtime(true);

        foreach ($this->client->pages($deployment, [], $maxPages) as $sessions) {
            $pages++;
            $found += count($sessions);

            foreach ($sessions as $session) {
                $address = DiscoveredEosServer::addressOf($session);

                if ($address === null) {
                    continue;
                }

                [$ip, $port] = $address;
                $key = $ip.':'.$port;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                if ($only !== null && ! isset($only[$key])) {
                    $skipped++;

                    continue;
                }

                $server = DiscoveredEosServer::fromApi($session);

                if ($server === null) {
                    continue;
                }

                $onServer($server);
            }
        }

        $httpMs = (hrtime(true) - $httpStarted) / 1e6;

        return new EosSweepResult(
            found: $found,
            distinct: count($seen),
            pages: $pages,
            skipped: $skipped,
            httpMs: $httpMs,
        );
    }
}
