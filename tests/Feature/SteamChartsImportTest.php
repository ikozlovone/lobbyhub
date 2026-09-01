<?php

namespace Tests\Feature;

use App\Enums\QueryProtocol;
use App\Models\Game;
use App\Services\Discovery\SteamChartsImport;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading SteamDB's top-100 chart into the catalog.
 *
 * The markup fixture is a real slice of the page rather than a hand-written
 * approximation of it: this is a scraper, and the only thing it can get wrong
 * that a test can catch is the shape of what it is scraping.
 */
class SteamChartsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_reads_the_chart_as_shipped(): void
    {
        $this->page(file_get_contents(base_path('tests/Fixtures/steamdb-charts.html')));

        $chart = $this->import()->chart();

        $this->assertCount(3, $chart);
        $this->assertSame(730, $chart[0]->appId);
        $this->assertSame('counter-strike-2', $chart[0]->slug);
        $this->assertSame('Counter-Strike 2', $chart[0]->name);
        // Rank order, which is the order they are written in.
        $this->assertSame(['Counter-Strike 2', 'Dota 2', 'PUBG: BATTLEGROUNDS'], array_map(
            fn ($game) => $game->name,
            $chart,
        ));
    }

    public function test_it_adds_the_charted_games_the_catalog_does_not_have(): void
    {
        $this->page($this->rows([
            [730, 'counter-strike-2', 'Counter-Strike 2'],
            [570, 'dota-2', 'Dota 2'],
        ]));

        // Counter-Strike is already here; Dota is not, and never will have a
        // server to monitor — which is the point of the review these wait for.
        $report = $this->import()->run();

        $this->assertSame(1, $report->existing);
        $this->assertSame(1, $report->created);

        $dota = Game::where('slug', 'dota-2')->firstOrFail();
        $this->assertSame(570, $dota->steam_appid);
        $this->assertSame(QueryProtocol::Source, $dota->query_protocol);
        $this->assertFalse($dota->is_active);
    }

    /** Publishers write names for lawyers; a server list is not one. */
    public function test_trademark_furniture_is_dropped_from_the_name(): void
    {
        $this->page($this->rows([
            [1172470, 'apex-legends', 'Apex Legends&trade;'],
            [1938090, 'call-of-duty', 'Call of Duty&reg;'],
        ]));

        $this->import()->run();

        $this->assertSame('Apex Legends', Game::where('slug', 'apex-legends')->value('name'));
        $this->assertSame('Call of Duty', Game::where('slug', 'call-of-duty')->value('name'));
    }

    /**
     * A scraper that quietly finds nothing is worse than one that fails: a
     * chart of zero games and a redesigned page look identical from here.
     */
    public function test_a_page_it_cannot_read_is_an_error_not_an_empty_chart(): void
    {
        $this->page('<html><body><p>Nothing like the chart at all.</p></body></html>');

        $report = $this->import()->run();

        $this->assertFalse($report->complete());
        $this->assertStringContainsString('markup', (string) $report->error);
        $this->assertSame(0, $report->created);
    }

    public function test_a_page_that_will_not_load_is_reported(): void
    {
        Http::fake(['steamdb.com/*' => Http::response('go away', 403)]);

        $report = $this->import()->run();

        $this->assertFalse($report->complete());
        $this->assertStringContainsString('403', (string) $report->error);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->page($this->rows([[570, 'dota-2', 'Dota 2']]));

        $this->assertSame(1, $this->import()->run(write: false)->created);
        $this->assertSame(0, Game::where('slug', 'dota-2')->count());
    }

    public function test_it_can_stop_partway_down_the_chart(): void
    {
        $this->page($this->rows([
            [570, 'dota-2', 'Dota 2'],
            [578080, 'pubg-battlegrounds', 'PUBG: BATTLEGROUNDS'],
        ]));

        $report = $this->import()->run(limit: 1);

        $this->assertSame(1, $report->found);
        $this->assertSame(1, Game::whereIn('slug', ['dota-2', 'pubg-battlegrounds'])->count());
    }

    private function import(): SteamChartsImport
    {
        return $this->app->make(SteamChartsImport::class);
    }

    private function page(string $html): void
    {
        Http::fake(['steamdb.com/*' => Http::response($html)]);
    }

    /** @param  list<array{0: int, 1: string, 2: string}>  $games */
    private function rows(array $games): string
    {
        $rows = array_map(
            fn (array $game) => sprintf(
                '<tr class="sc-row"><td class="sc-game-name"><a href="/en/tools/steam-charts/%d-%s" data-appid="%d">%s</a></td></tr>',
                $game[0],
                $game[1],
                $game[0],
                $game[2],
            ),
            $games,
        );

        return '<table>'.implode('', $rows).'</table>';
    }
}
