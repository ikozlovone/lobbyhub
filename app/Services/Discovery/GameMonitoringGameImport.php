<?php

namespace App\Services\Discovery;

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
 * A row carries what their list carries — an appid, a slug, a name — and
 * arrives switched off, because the two fields a game cannot do without are
 * not among them. See ImportedGame, which is where that policy lives and which
 * the SteamDB import shares.
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
                        //
                        // What the row says is ImportedGame's business — see
                        // there for why an imported game arrives switched off.
                        // `sort_order` puts these after everything already in
                        // the catalog, biggest of theirs first: their pages
                        // arrive sorted by server count, so it is their own
                        // ordering carried over.
                        Game::query()->create(
                            (new ImportedGame($appId, $slug, $name))->row(++$order, $active),
                        );
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
