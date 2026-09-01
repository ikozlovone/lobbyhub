<?php

namespace App\Services\Discovery;

use App\Models\Game;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The hundred most played games on Steam, from SteamDB's charts page.
 *
 * A one-off by design. gamemonitoring's list answers "which games have servers
 * somebody is tracking"; this one answers "which games are being played at
 * all", and the difference is the point of running it once: the charts carry
 * games with no dedicated servers to monitor — Dota 2 has none, and neither do
 * Blender or Bongo Cat, both of which are in today's hundred. So this is a
 * shortlist for a person to go through, not a catalog to publish, and every row
 * it writes is switched off (see ImportedGame).
 *
 * Read from the page rather than from Steam's own
 * `ISteamChartsService/GetMostPlayedGames`, which carries the same ranking:
 * the API answers with appids and nothing else, and a game needs a name. The
 * page has both in one anchor. If it ever stops parsing, that endpoint plus a
 * name lookup per appid is the fallback, not a heavier parser.
 *
 * Their robots.txt allows this path — only `/api/` is disallowed — and the
 * request identifies itself rather than pretending to be a browser. One
 * request, run by hand, once.
 */
class SteamChartsImport
{
    private const URL = 'https://steamdb.com/en/tools/steam-charts';

    /**
     * One row of the chart table:
     *
     *   <a href="/en/tools/steam-charts/730-counter-strike-2" data-appid="730">Counter-Strike 2</a>
     *
     * The appid appears twice and the backreference insists they are the same
     * one, so a page that changes shape enough to break that pairing matches
     * nothing at all rather than pairing a name with the wrong game.
     */
    private const ROW = '~href="/en/tools/steam-charts/(\d+)-([a-z0-9\-]+)"\s+data-appid="\1">([^<]+)</a>~';

    /**
     * @param  bool  $write  false reads the chart and counts
     * @param  int|null  $limit  how far down the hundred to go
     * @param  bool  $active  create them switched on, which is not the default
     */
    public function run(bool $write = true, ?int $limit = null, bool $active = false): GameImportReport
    {
        $startedAt = hrtime(true);

        $byAppId = Game::query()->whereNotNull('steam_appid')->pluck('id', 'steam_appid');
        $bySlug = Game::query()->pluck('id', 'slug');
        $order = (int) Game::query()->max('sort_order');

        $found = $existing = $created = $skipped = 0;
        $error = null;

        try {
            foreach ($this->chart($limit) as $game) {
                $found++;

                if ($byAppId->has($game->appId) || $bySlug->has($game->slug)) {
                    $existing++;

                    continue;
                }

                $created++;

                if ($write) {
                    // One at a time: GameObserver writes the `server_states`
                    // partition on the way through.
                    Game::query()->create($game->row(++$order, $active));
                }

                $byAppId->put($game->appId, 0);
                $bySlug->put($game->slug, 0);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return new GameImportReport(
            found: $found,
            existing: $existing,
            created: $created,
            skipped: $skipped,
            pages: 1,
            totalMs: (hrtime(true) - $startedAt) / 1e6,
            error: $error,
        );
    }

    /**
     * The chart, in rank order.
     *
     * @return list<ImportedGame>
     */
    public function chart(?int $limit = null): array
    {
        $response = Http::timeout(30)
            ->connectTimeout(15)
            ->retry(3, fn (int $attempt) => $attempt * 1000, throw: false)
            // Named honestly. This is a page their robots.txt allows anybody
            // to read; there is nothing here to hide behind a browser string.
            ->withHeaders(['User-Agent' => 'LobbyHub/1.0 (+https://lobbyhub.gg)'])
            ->get(self::URL);

        if ($response->failed()) {
            throw new RuntimeException("SteamDB returned {$response->status()} for the charts page.");
        }

        preg_match_all(self::ROW, $response->body(), $matches, PREG_SET_ORDER);

        if ($matches === []) {
            // Loudly, rather than reporting a chart of zero games: a silent
            // nothing from a scraper is indistinguishable from a quiet week.
            throw new RuntimeException(
                'SteamDB returned a page with no chart rows in it — the markup this reads has changed.',
            );
        }

        $games = [];

        foreach ($matches as $match) {
            [, $appId, $slug, $name] = $match;

            $games[] = new ImportedGame(
                appId: (int) $appId,
                slug: $slug,
                name: $this->name($name),
            );

            if ($limit !== null && count($games) >= $limit) {
                break;
            }
        }

        return $games;
    }

    /**
     * Their published title, made into a catalog name.
     *
     * Entities decoded because the page is HTML, and the trademark furniture
     * dropped: "Apex Legends™" and "Call of Duty®" are how a publisher writes
     * a name in a legal notice, not how a heading on a server list should read.
     */
    private function name(string $raw): string
    {
        $name = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace(['™', '®', '©'], '', $name));
    }
}
