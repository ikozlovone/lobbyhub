<?php

namespace App\Services\Discovery;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\CatalogCounters;
use App\Services\Catalog\FrontendCache;
use App\Services\Monitoring\ServerStatePartitionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One game's list on gamemonitoring.net, reconciled against the catalog.
 *
 * Two things come out of a pass, and the second is the point of the first.
 *
 * Servers we already hold get `gamemonitoring_seen_at` written, and servers we
 * do not hold get written as rows that carry it from birth. What is left when
 * the pass finishes is the set with no mark at all: addresses in this catalog
 * that the nearest competitor does not list. That set is what a later cleanup
 * is meant to judge — a server nobody else has seen is usually a machine that
 * stopped existing, and a page for one of those is a thin page we asked a
 * search engine to index.
 *
 * The mark is only ever written where it is missing. It reads as "first
 * matched", not "last seen", and that is a deliberate trade: `servers` is the
 * cold half of the schema, kept apart from `server_states` precisely so that
 * periodic sweeps do not rewrite it. Refreshing the date on three hundred
 * thousand rows every pass would make the cold table hot again for a fact that
 * only has to be true once.
 *
 * What an imported row carries: an address, and nothing else. Their payload has
 * a name, a player count, a map and a country in it, all of which are somebody
 * else's measurement of a machine we are about to query ourselves. So a new row
 * is written the way the admin importer writes a pasted address — status
 * `unknown`, named after its own address, invisible to every listing — and the
 * first successful query of our own is what gives it a name, a slug and a place
 * in the catalog. A competitor's list is a set of addresses worth checking, not
 * a set of facts worth publishing.
 */
class GameMonitoringSync
{
    /** Rows per INSERT, matching the other bulk writers here. */
    private const CHUNK = 500;

    public function __construct(
        private readonly GameMonitoringClient $client,
        private readonly ServerStatePartitionManager $partitions,
        private readonly CatalogCounters $counters,
        private readonly FrontendCache $frontend,
    ) {}

    /**
     * @param  bool  $write  false walks the list and counts what it would do
     * @param  int|null  $maxPages  stop after this many pages of their list
     */
    public function run(Game $game, bool $write = true, ?int $maxPages = null): GameMonitoringReport
    {
        if ($game->steam_appid === null) {
            throw new RuntimeException("{$game->slug} has no steam_appid; gamemonitoring is keyed by one.");
        }

        $startedAt = hrtime(true);
        $now = now();

        // A game created before the partition manager shipped, or one whose
        // partition was dropped by hand, would otherwise fail on the first
        // state insert. Idempotent.
        if ($write) {
            $this->partitions->ensureFor($game);
        }

        $existing = $this->existing($game);

        $found = $matched = $marked = $created = $skipped = $pages = 0;

        /** @var list<int> $toMark */
        $toMark = [];
        /** @var list<array<string, mixed>> $inserts */
        $inserts = [];

        foreach ($this->client->pages((int) $game->steam_appid, $maxPages) as $items) {
            $pages++;

            foreach ($items as $item) {
                $found++;

                $address = $this->address($item);

                if ($address === null) {
                    $skipped++;

                    continue;
                }

                [$ip, $port, $queryPort] = $address;
                $entry = $this->lookup($existing, $item, $ip, $port, $queryPort);

                if ($entry !== null) {
                    [$id, $isMarked, $trashed] = $entry;

                    // A server somebody here deleted stays deleted. It is still
                    // on their list, which is exactly the situation this would
                    // silently undo.
                    if ($trashed) {
                        $skipped++;

                        continue;
                    }

                    $matched++;

                    if (! $isMarked) {
                        $marked++;

                        if ($write) {
                            $toMark[] = $id;
                        }
                    }

                    continue;
                }

                $created++;

                if ($write) {
                    $inserts[] = $this->newRow($game, $ip, $port, $queryPort, $now);
                }

                // Written into the map so a list carrying one machine twice —
                // two ports, or a game and query pair that both match — does
                // not write it twice.
                $existing[$ip.':'.$port] = [0, true, false];
            }

            if ($write) {
                $toMark = $this->flushMarks($toMark, $now, force: false);
                $inserts = $this->flushInserts($game, $inserts, $now, force: false);
            }
        }

        if ($write) {
            $this->flushMarks($toMark, $now, force: true);
            $this->flushInserts($game, $inserts, $now, force: true);

            if ($created > 0) {
                // New rows are invisible until the monitor reaches them, so no
                // count moves yet — but the servers this adds are the reason
                // the counters exist, and the first ones verify within the
                // minute. Cheap once per game; never once per row.
                $this->counters->refresh();
                $this->frontend->invalidate('games');
            }
        }

        return new GameMonitoringReport(
            found: $found,
            matched: $matched,
            marked: $marked,
            created: $created,
            skipped: $skipped,
            pages: $pages,
            totalMs: (hrtime(true) - $startedAt) / 1e6,
        );
    }

    /**
     * Every address this game already answers to, and whether it is spoken for.
     *
     * The same shape SteamCatalogSync loads for the same reason: three scalars
     * per row rather than a model, because a game can hold ninety thousand of
     * them and the map is built before a single page is read.
     *
     * Soft-deleted rows are in it on purpose — see the `trashed` branch above.
     *
     * @return array<string, array{0: int, 1: bool, 2: bool}>
     */
    private function existing(Game $game): array
    {
        $keyed = [];

        // chunkById, not chunk: offset paging makes the last page of a large
        // game cost the whole table. Same reasoning as the Steam sweep's.
        DB::table('servers')
            ->where('game_id', $game->id)
            ->select(['id', 'host', 'port', 'query_port', 'ip_address', 'game_port', 'gamemonitoring_seen_at', 'deleted_at'])
            ->chunkById(2000, function (Collection $rows) use (&$keyed) {
                foreach ($rows as $row) {
                    $entry = [
                        (int) $row->id,
                        $row->gamemonitoring_seen_at !== null,
                        $row->deleted_at !== null,
                    ];

                    $keyed[$row->host.':'.$row->port] ??= $entry;

                    if ($row->ip_address !== null) {
                        // Every port the row is known by. A server submitted by
                        // domain has an IP the monitor resolved and a connect
                        // port the owner typed; the game port arrives later
                        // from a query, and their list may name any of them.
                        $keyed[$row->ip_address.':'.$row->port] ??= $entry;

                        if ($row->game_port !== null) {
                            $keyed[$row->ip_address.':'.$row->game_port] ??= $entry;
                        }

                        if ($row->query_port !== null) {
                            $keyed[$row->ip_address.':'.$row->query_port] ??= $entry;
                        }
                    }
                }
            });

        return $keyed;
    }

    /**
     * The address on one of their rows: IP, connect port, query port.
     *
     * Null when it cannot be read. Their list carries rows with the address
     * withheld and rows whose port is zero, and neither is something to write
     * down as a server.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: int, 2: int|null}|null
     */
    private function address(array $item): ?array
    {
        $ip = trim((string) ($item['ip'] ?? ''));
        $port = (int) ($item['port'] ?? 0);
        $queryPort = (int) ($item['query'] ?? 0);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [$ip, $port, $queryPort >= 1 && $queryPort <= 65535 ? $queryPort : null];
    }

    /**
     * The catalog row this list entry is, if it is one of ours.
     *
     * Four ways in, cheapest first: the address as they give it, the query port
     * they give beside it, and the hostname on their row — a server we hold by
     * domain has neither of their two IP keys.
     *
     * @param  array<string, array{0: int, 1: bool, 2: bool}>  $existing
     * @param  array<string, mixed>  $item
     * @return array{0: int, 1: bool, 2: bool}|null
     */
    private function lookup(array $existing, array $item, string $ip, int $port, ?int $queryPort): ?array
    {
        $domain = trim((string) ($item['domain'] ?? ''));

        $keys = [$ip.':'.$port];

        if ($queryPort !== null && $queryPort !== $port) {
            $keys[] = $ip.':'.$queryPort;
        }

        if ($domain !== '') {
            $keys[] = $domain.':'.$port;
        }

        foreach ($keys as $key) {
            if (isset($existing[$key])) {
                return $existing[$key];
            }
        }

        return null;
    }

    /**
     * A row for an address nobody here has.
     *
     * Named and slugged after the address, both placeholders: the first
     * successful query adopts the name the server reports and rewrites the slug
     * with it, which is safe precisely because the row has never been public
     * (see QueryServer::adoptReportedName). Deriving either from their payload
     * would put a competitor's copy of a name in one of our URLs.
     *
     * @return array<string, mixed>
     */
    private function newRow(Game $game, string $ip, int $port, ?int $queryPort, Carbon $now): array
    {
        return [
            'game_id' => $game->id,
            'host' => $ip,
            'port' => $port,
            'query_port' => $queryPort,
            'ip_address' => $ip,
            'slug' => str_replace([':', '.'], '-', $ip).'-'.$port,
            'name' => $ip.':'.$port,
            'is_active' => true,
            'gamemonitoring_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Write the mark on rows that did not have it.
     *
     * `whereNull` in the statement as well as in the map: the map was read
     * before the first page and a pass over a large game takes minutes, in
     * which another run of this could have marked the same rows.
     *
     * @param  list<int>  $ids
     * @return list<int> what is still waiting
     */
    private function flushMarks(array $ids, Carbon $now, bool $force): array
    {
        if ($ids === [] || (! $force && count($ids) < self::CHUNK)) {
            return $ids;
        }

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('servers')
                ->whereIn('id', $chunk)
                ->whereNull('gamemonitoring_seen_at')
                ->update(['gamemonitoring_seen_at' => $now]);
        }

        return [];
    }

    /**
     * Write the new rows, then a state row each.
     *
     * The state row can only be built once the insert has minted an id, so the
     * ids are read back by slug — the same two-step the Steam sweep does.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> what is still waiting
     */
    private function flushInserts(Game $game, array $rows, Carbon $now, bool $force): array
    {
        if ($rows === [] || (! $force && count($rows) < self::CHUNK)) {
            return $rows;
        }

        $this->disambiguate($rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            Server::query()->insert($chunk);
        }

        $ids = DB::table('servers')
            ->where('game_id', $game->id)
            ->whereIn('slug', array_column($rows, 'slug'))
            ->pluck('id', 'slug');

        $states = [];

        foreach ($ids as $slug => $id) {
            $states[] = [
                'server_id' => (int) $id,
                'game_id' => $game->id,
                // Hearsay until our own packet says otherwise, and hidden from
                // every listing while it is. `next_query_at` is now, so the
                // dispatcher picks it up in the current cycle rather than at
                // the end of a tier interval.
                'status' => ServerStatus::Unknown->value,
                'players_online' => 0,
                'players_max' => 0,
                'next_query_at' => $now,
                'failed_queries_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($states, self::CHUNK) as $chunk) {
            DB::table('server_states')->insert($chunk);
        }

        return [];
    }

    /**
     * Settle slug collisions for one batch in one query.
     *
     * The address makes these unique within a game by construction; what it
     * does not settle is two games behind one address, or a soft-deleted row
     * still holding the slug it was published under.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function disambiguate(array &$rows): void
    {
        $taken = DB::table('servers')
            ->whereIn('slug', array_column($rows, 'slug'))
            ->pluck('slug')
            ->flip();

        foreach ($rows as $index => $row) {
            $slug = $row['slug'];

            for ($n = 2; $taken->has($slug); $n++) {
                $slug = $row['slug']."-{$n}";
            }

            $taken->put($slug, true);
            $rows[$index]['slug'] = $slug;
        }
    }
}
