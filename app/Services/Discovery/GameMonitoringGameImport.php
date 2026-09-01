<?php

namespace App\Services\Discovery;

use App\Enums\QueryProtocol;
use App\Models\Game;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Their game catalogue, minus ours.
 *
 * gamemonitoring lists 380 games and this site lists 46, and the ones in
 * between are games whose servers could be monitored here and are not. This
 * writes the rows; nothing about them is marked, and nothing is reconciled —
 * a game is either in the catalog or it is not, and the interesting question
 * (which of their *servers* we hold) belongs to GameMonitoringSync.
 *
 * Two things a row needs that their list does not carry, and both are the
 * reason an imported game arrives switched off.
 *
 * `query_protocol` is guessed as Valve A2S, which is what a Steam appid all
 * but implies — of the three protocols this monitor speaks, it is the only one
 * that fits a game published on Steam. `default_port` is a submission-form
 * hint with no honest source here at all: theirs is a list of servers, not of
 * conventions, and 27015 is Valve's own default rather than anything measured.
 * ARK's is 7777 and Rust's is 28015, so the hint is wrong more often than not
 * and an admin is expected to correct it.
 *
 * Which is the shape of the whole thing: `is_active` is false, so a new game
 * is not on the rail, not in the sitemap, and not in a listing, and somebody
 * decides it belongs there after giving it artwork, a description and a port.
 * Adding three hundred untouched game pages to a catalog of 46 is not a
 * catalog, it is a doorway for a thin-content penalty.
 */
class GameMonitoringGameImport
{
    public function __construct(private readonly GameMonitoringClient $client) {}

    /**
     * @param  bool  $write  false walks their catalogue and counts
     * @param  int  $minServers  their server count a game must reach to be
     *                           worth a row; sixty-three of theirs have none
     * @param  bool  $active  create them switched on, which is not the default
     */
    public function run(
        bool $write = true,
        int $minServers = 1,
        ?int $maxPages = null,
        bool $active = false,
    ): GameImportReport {
        $startedAt = hrtime(true);

        $byAppId = Game::query()->whereNotNull('steam_appid')->pluck('id', 'steam_appid');
        $bySlug = Game::query()->pluck('id', 'slug');
        $order = (int) Game::query()->max('sort_order');

        $found = $existing = $created = $skipped = $pages = 0;
        $error = null;

        try {
            foreach ($this->client->games($maxPages) as $items) {
                $pages++;

                foreach ($items as $item) {
                    $found++;

                    $appId = (int) ($item['steam_id'] ?? 0);
                    $slug = trim((string) ($item['url'] ?? ''));
                    $name = trim((string) ($item['name'] ?? ''));
                    $servers = (int) ($item['servers'] ?? 0);

                    // No appid is no monitoring and no reconciling: their
                    // server list is keyed on one, and so is every protocol
                    // guess below it.
                    if ($appId < 1 || $slug === '' || $name === '') {
                        $skipped++;

                        continue;
                    }

                    if ($byAppId->has($appId) || $bySlug->has($slug)) {
                        $existing++;

                        continue;
                    }

                    // A game with no servers on the largest tracker of them is
                    // an empty page waiting to be written.
                    if ($servers < $minServers) {
                        $skipped++;

                        continue;
                    }

                    $created++;

                    if ($write) {
                        // One at a time rather than batched: GameObserver
                        // creates the `server_states` partition on the way
                        // through, and a game without one takes its first
                        // server insert down with it.
                        Game::query()->create([
                            'slug' => $slug,
                            'name' => mb_substr($name, 0, 255),
                            'steam_appid' => $appId,
                            'query_protocol' => QueryProtocol::Source,
                            'default_port' => 27015,
                            'is_active' => $active,
                            // After everything already here, biggest of theirs
                            // first — the pages arrive sorted by server count,
                            // so this is their own ordering carried over.
                            'sort_order' => ++$order,
                        ]);
                    }

                    // Into both maps: their catalogue has no duplicate appids
                    // or slugs today, and a row written twice is a unique
                    // violation rather than a miscount.
                    $byAppId->put($appId, 0);
                    $bySlug->put($slug, 0);
                }
            }
        } catch (Throwable $e) {
            // Same rule as the server pass: what was read is kept, and the
            // reason travels back with the numbers. Re-running is safe — a
            // game already written matches on both keys.
            $error = $e->getMessage();
        }

        return new GameImportReport(
            found: $found,
            existing: $existing,
            created: $created,
            skipped: $skipped,
            pages: $pages,
            totalMs: (hrtime(true) - $startedAt) / 1e6,
            error: $error,
        );
    }

    /** Games that were added but nobody has switched on yet. */
    public function awaitingReview(): int
    {
        return (int) DB::table('games')->where('is_active', false)->count();
    }
}
