<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\BulkServerImport;
use App\Services\Geo\GeoResolver;
use App\Services\Monitoring\PollingSchedule;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_imports_a_pasted_list_without_querying_anything(): void
    {
        $report = $this->import("1.2.3.4:28015\n5.6.7.8:28020");

        $this->assertSame(2, $report->added);
        $this->assertSame(2, Server::count());

        // Unknown, not offline: nothing has been asked, so nothing is known.
        // This is also what keeps them out of every public listing until the
        // monitor confirms them — see Server::scopeVerified.
        $this->assertSame(ServerStatus::Unknown, Server::first()->status);
        $this->assertNull(Server::first()->last_queried_at);
    }

    #[DataProvider('separators')]
    public function test_the_query_port_may_follow_any_common_separator(string $line): void
    {
        $this->import($line);

        $server = Server::sole();

        $this->assertSame('1.2.3.4', $server->host);
        $this->assertSame(28015, $server->port);
        $this->assertSame(28016, $server->query_port);
    }

    public static function separators(): array
    {
        return [
            'pipe' => ['1.2.3.4:28015|28016'],
            'comma' => ['1.2.3.4:28015,28016'],
            'space' => ['1.2.3.4:28015 28016'],
            'semicolon' => ['1.2.3.4:28015;28016'],
            'tab' => ["1.2.3.4:28015\t28016"],
        ];
    }

    public function test_the_port_may_be_left_off_and_falls_back_to_the_game_default(): void
    {
        $this->import('play.example.com');

        $server = Server::sole();

        $this->assertSame(Game::where('slug', 'rust')->value('default_port'), $server->port);
        $this->assertNull($server->query_port);
    }

    public function test_it_reports_the_lines_it_could_not_read_and_keeps_going(): void
    {
        $report = $this->import("1.2.3.4:28015\nnot an address\n5.6.7.8:28020");

        $this->assertSame(2, $report->added);
        $this->assertCount(1, $report->rejected);
        // Numbered as the person pasting sees them, blank lines included.
        $this->assertSame(2, $report->rejected[0]['line']);
    }

    public function test_a_non_numeric_query_port_is_refused_rather_than_dropped(): void
    {
        $report = $this->import('1.2.3.4:28015|nonsense');

        $this->assertSame(0, $report->added);
        $this->assertStringContainsString('Query port', $report->rejected[0]['reason']);
    }

    public function test_blank_lines_and_comments_are_ignored_without_breaking_numbering(): void
    {
        $report = $this->import("# rust box one\n\n1.2.3.4:28015\n\nbad line");

        $this->assertSame(1, $report->added);
        $this->assertSame(5, $report->rejected[0]['line']);
    }

    public function test_a_server_already_listed_is_skipped_not_duplicated(): void
    {
        $this->import('1.2.3.4:28015');
        $report = $this->import('1.2.3.4:28015');

        $this->assertSame(0, $report->added);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(1, Server::count());
    }

    public function test_a_deleted_server_is_restored_with_its_slug_and_history(): void
    {
        $this->import('1.2.3.4:28015');
        $original = Server::sole();
        $original->delete();

        $report = $this->import('1.2.3.4:28015');

        $this->assertSame(1, $report->restored);
        $this->assertSame($original->slug, Server::sole()->slug);
        $this->assertSame($original->id, Server::sole()->id);
    }

    public function test_imported_servers_are_dispatched_before_the_existing_backlog(): void
    {
        // A server that is already overdue by a week — without the never-queried
        // priority it would be first in line, and the import would wait.
        Server::factory()->create([
            'game_id' => Game::where('slug', 'rust')->value('id'),
            'status' => ServerStatus::Online,
            'last_queried_at' => now()->subWeek(),
            'next_query_at' => now()->subWeek(),
        ]);

        $this->import('1.2.3.4:28015');

        $this->artisan('servers:query --limit=1')->assertSuccessful();

        // The lease is what proves which one was picked: the dispatcher pushes
        // exactly the servers it queued out of "due".
        $imported = Server::where('host', '1.2.3.4')->sole();
        $this->assertTrue($imported->next_query_at->isFuture());
    }

    public function test_the_first_successful_query_gives_an_imported_server_its_real_name(): void
    {
        $this->import('1.2.3.4:28015');
        $server = Server::sole();

        $this->assertSame('1.2.3.4:28015', $server->name);

        // The answer the monitor would have got, handed to the job directly —
        // the same path ServerSubmission uses to record a query it already made.
        (new QueryServer($server, new QueryResult(
            playersOnline: 12,
            playersMax: 100,
            motd: 'Rusty Moose |EU Monday|',
        )))->handle(app(ServerQueryManager::class), app(GeoResolver::class), app(PollingSchedule::class));

        $renamed = $server->fresh();

        $this->assertSame('Rusty Moose |EU Monday|', $renamed->name);
        // The slug follows, because it has never been public: until this query
        // the row was `unknown` and every listing filtered it out.
        $this->assertStringStartsWith('rusty-moose-eu-monday', $renamed->slug);
    }

    public function test_a_name_somebody_chose_is_never_overwritten_by_a_later_query(): void
    {
        $this->import('1.2.3.4:28015');
        $server = Server::sole();
        $server->forceFill(['name' => 'Edited in the admin'])->save();

        (new QueryServer($server, new QueryResult(
            playersOnline: 1,
            playersMax: 10,
            motd: 'Whatever the server calls itself',
        )))->handle(app(ServerQueryManager::class), app(GeoResolver::class), app(PollingSchedule::class));

        $this->assertSame('Edited in the admin', $server->fresh()->name);
    }

    public function test_the_screen_posts_a_list_and_shows_the_outcome(): void
    {
        $this->get(route('admin.servers.import'))->assertOk();

        $this->post(route('admin.servers.import.store'), [
            'game' => 'rust',
            'servers' => "1.2.3.4:28015|28016\nnot an address",
        ])
            ->assertOk()
            ->assertSee('1 added')
            ->assertSee('could not be read');

        $this->assertSame(1, Server::count());
    }

    public function test_it_refuses_a_game_it_cannot_monitor(): void
    {
        $this->post(route('admin.servers.import.store'), [
            'game' => 'no-such-game',
            'servers' => '1.2.3.4:28015',
        ])->assertSessionHasErrors('game');
    }

    private function import(string $input)
    {
        return app(BulkServerImport::class)->import(
            Game::where('slug', 'rust')->firstOrFail(),
            $input,
        );
    }
}
