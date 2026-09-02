<?php

namespace Tests\Feature;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\User;
use App\Services\Stats\ClickHouseClient;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Taking dead listings out of the catalog.
 *
 * A destructive command, so most of these are about what it refuses to touch.
 * The one that deletes is easy to get right; the ones that spare a paid
 * placement, an owner's server, and a submission from this morning are the
 * ones that make it safe to run on a schedule.
 */
class PruneServersTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);

        $this->game = Game::where('slug', 'rust')->firstOrFail();
    }

    public function test_it_removes_a_server_that_stopped_answering(): void
    {
        $dead = $this->server('dead', offlineFor: 10);
        $alive = $this->server('alive', offlineFor: 2);

        $this->artisan('servers:prune --empty-days=0 --force')->assertSuccessful();

        $this->assertSoftDeleted('servers', ['id' => $dead->id]);
        $this->assertNotSoftDeleted('servers', ['id' => $alive->id]);
    }

    /**
     * A row that has never answered is judged on when it was written instead.
     * The submission form and the bulk import both create servers that have
     * not been reached yet, and a catalog that deletes those before the
     * monitor gets to them is a form that quietly does nothing.
     */
    public function test_a_server_that_has_never_answered_is_judged_on_its_age(): void
    {
        $old = $this->server('never-answered-old', offlineFor: null, createdDaysAgo: 30);
        $fresh = $this->server('never-answered-today', offlineFor: null, createdDaysAgo: 0);

        $this->artisan('servers:prune --empty-days=0 --force')->assertSuccessful();

        $this->assertSoftDeleted('servers', ['id' => $old->id]);
        $this->assertNotSoftDeleted('servers', ['id' => $fresh->id]);
    }

    /** Somebody paid for that placement; a cleanup does not get to end it. */
    public function test_a_promoted_server_is_never_removed(): void
    {
        $promoted = $this->server('promoted', offlineFor: 30);
        $promoted->forceFill(['promoted_until' => now()->addMonth()])->save();

        $this->artisan('servers:prune --empty-days=0 --force')->assertSuccessful();

        $this->assertNotSoftDeleted('servers', ['id' => $promoted->id]);
    }

    /**
     * A claimed server has a person attached, and "your server was deleted
     * while you were away" is a worse message than a stale listing — unless
     * that is the intent, which is what the flag is for.
     */
    public function test_a_claimed_server_is_spared_unless_asked_for(): void
    {
        $claimed = $this->server('claimed', offlineFor: 30);
        $claimed->forceFill([
            'user_id' => User::factory()->create()->id,
            'claimed_at' => now(),
        ])->save();

        $this->artisan('servers:prune --empty-days=0 --force')->assertSuccessful();
        $this->assertNotSoftDeleted('servers', ['id' => $claimed->id]);

        $this->artisan('servers:prune --empty-days=0 --include-claimed --force')->assertSuccessful();
        $this->assertSoftDeleted('servers', ['id' => $claimed->id]);
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $dead = $this->server('dead', offlineFor: 10);

        $this->artisan('servers:prune --empty-days=0 --dry')->assertSuccessful();

        $this->assertNotSoftDeleted('servers', ['id' => $dead->id]);
    }

    /**
     * The other rule, and the only one that needs ClickHouse: Postgres knows
     * how many players are on a server now and remembers nothing, so "nobody
     * all week" can only be answered from the samples.
     */
    public function test_it_removes_a_server_nobody_has_played_on(): void
    {
        $empty = $this->server('empty-but-answering', offlineFor: null, online: true);
        $busy = $this->server('busy', offlineFor: null, online: true);

        config(['services.clickhouse.host' => 'clickhouse.test']);
        $this->app->forgetInstance(ClickHouseClient::class);
        Http::fake([
            'clickhouse.test*' => Http::response(['data' => [['server_id' => (string) $empty->id]]]),
        ]);

        $this->artisan('servers:prune --offline-days=0 --force')->assertSuccessful();

        $this->assertSoftDeleted('servers', ['id' => $empty->id]);
        $this->assertNotSoftDeleted('servers', ['id' => $busy->id]);
    }

    /** Without the samples the rule cannot be answered, and says so. */
    public function test_the_empty_rule_is_skipped_rather_than_guessed(): void
    {
        $server = $this->server('answering', offlineFor: null, online: true);

        $this->artisan('servers:prune --offline-days=0 --force')
            ->expectsOutputToContain('No ClickHouse configured')
            ->assertSuccessful();

        $this->assertNotSoftDeleted('servers', ['id' => $server->id]);
    }

    /** The counts every page reads are wrong the moment a row goes. */
    public function test_it_recounts_the_catalog_afterwards(): void
    {
        $this->server('dead', offlineFor: 10);
        $this->server('alive', offlineFor: null, online: true);

        $this->game->forceFill(['servers_count' => 99])->save();

        $this->artisan('servers:prune --empty-days=0 --force')->assertSuccessful();

        // One left, and the denormalised count says so.
        $this->assertSame(1, $this->game->refresh()->servers_count);
    }

    public function test_both_rules_off_is_refused(): void
    {
        $this->artisan('servers:prune --offline-days=0 --empty-days=0')->assertFailed();
    }

    private function server(
        string $slug,
        ?int $offlineFor,
        int $createdDaysAgo = 30,
        bool $online = false,
    ): Server {
        $server = Server::factory()->create([
            'game_id' => $this->game->id,
            'slug' => $slug,
            'status' => $online ? ServerStatus::Online : ServerStatus::Offline,
            'last_online_at' => $offlineFor === null ? null : now()->subDays($offlineFor),
        ]);

        // `created_at` is what a never-answered row is judged on.
        $server->forceFill(['created_at' => now()->subDays($createdDaysAgo)])->save();

        if ($online) {
            $server->state()->update(['last_online_at' => now()]);
        }

        return $server;
    }
}
