<?php

namespace Tests\Feature;

use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The half of a queue flush that is easy to forget.
 *
 * QueryServer's uniqueness lock lives in the cache, not in the queue, so
 * emptying the queue by hand leaves every affected server locked out of being
 * queried again until the lock expires on its own.
 */
class ReleaseMonitoringLocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_releases_a_lock_whose_query_is_gone(): void
    {
        $server = $this->server();
        $this->lock($server);

        // Nothing queued: the job this lock belonged to has been cleared away.
        $this->artisan('monitoring:unlock')
            ->expectsOutputToContain('released 1 stuck lock(s)')
            ->assertSuccessful();

        $this->assertFalse($this->isLocked($server), 'The server should be queryable again.');
    }

    public function test_it_says_so_when_there_was_nothing_stuck(): void
    {
        $this->server();

        $this->artisan('monitoring:unlock')
            ->expectsOutputToContain('released 0 stuck lock(s)')
            ->assertSuccessful();
    }

    /**
     * The mistake this refuses to make: a lock released while its query is still
     * queued lets the dispatcher queue a second copy, which is the whole failure
     * the lock exists to prevent.
     */
    public function test_it_refuses_while_queries_are_still_queued(): void
    {
        $server = $this->server();

        Queue::fake();
        QueryServer::dispatch($server);

        $this->artisan('monitoring:unlock')->assertFailed();

        $this->assertTrue($this->isLocked($server), 'The lock should have been left alone.');
    }

    public function test_force_releases_anyway(): void
    {
        $server = $this->server();

        Queue::fake();
        QueryServer::dispatch($server);

        $this->artisan('monitoring:unlock', ['--force' => true])->assertSuccessful();

        $this->assertFalse($this->isLocked($server));
    }

    private function server(): Server
    {
        return Server::factory()->create([
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
        ]);
    }

    private function lock(Server $server): void
    {
        (new UniqueLock(app(Cache::class)))->acquire(new QueryServer($server));
    }

    /** Held is the same thing as "cannot be acquired"; hand it straight back. */
    private function isLocked(Server $server): bool
    {
        $lock = app(Cache::class)->lock(UniqueLock::getKey(new QueryServer($server)), 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }
}
