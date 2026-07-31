<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Vote;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_monitoring_counts_servers_by_status(): void
    {
        $this->server(['status' => ServerStatus::Online]);
        $this->server(['status' => ServerStatus::Offline]);
        // Imported by discovery and not reached yet — the number this page
        // exists to make visible.
        $this->server(['status' => ServerStatus::Unknown, 'last_queried_at' => null]);

        $response = $this->get('/admin')->assertOk();

        $response->assertSee('active servers');
        $response->assertViewHas('statuses', fn (array $statuses) => $statuses['total'] === 3
            && $statuses['online'] === 1
            && $statuses['offline'] === 1
            && $statuses['unknown'] === 1
            && $statuses['never_queried'] === 1);
    }

    public function test_monitoring_reports_the_average_answer_time(): void
    {
        $server = $this->server(['status' => ServerStatus::Online]);

        ServerStat::create([
            'server_id' => $server->id,
            'recorded_at' => now()->subMinutes(10),
            'is_online' => true,
            'players_online' => 10,
            'players_max' => 100,
            'latency_ms' => 40,
        ]);
        ServerStat::create([
            'server_id' => $server->id,
            'recorded_at' => now()->subMinutes(5),
            'is_online' => false,
            'players_online' => 0,
            'players_max' => 100,
            'latency_ms' => 60,
        ]);

        $response = $this->get('/admin')->assertOk();

        $response->assertViewHas('timings', fn (array $timings) => $timings['samples_24h'] === 2
            && $timings['avg_latency_ms'] === 50
            && $timings['failures_24h'] === 1
            && $timings['failure_rate'] === 50.0);
    }

    public function test_the_user_list_shows_how_each_account_signs_in(): void
    {
        $withSteam = User::factory()->create(['name' => 'Steam Person', 'email' => 'steam@example.com']);
        SocialAccount::create([
            'user_id' => $withSteam->id,
            'provider' => 'steam',
            'provider_id' => '7656119',
            'nickname' => 'steamy',
        ]);

        User::factory()->create(['name' => 'Code Person', 'email' => 'code@example.com']);

        $response = $this->get('/admin/users')->assertOk();

        $response->assertSee('Steam Person');
        $response->assertSee('steam');
        // An account with no provider row signed in with an emailed code, and
        // the page says so rather than leaving the column blank.
        $response->assertSee('email code');
        $response->assertViewHas('totals', fn (array $totals) => $totals['users'] === 2
            && $totals['with_provider'] === 1
            && $totals['code_only'] === 1);
    }

    public function test_the_provider_filter_narrows_the_list(): void
    {
        $steam = User::factory()->create(['name' => 'Steam Person']);
        SocialAccount::create([
            'user_id' => $steam->id, 'provider' => 'steam', 'provider_id' => '1',
        ]);
        User::factory()->create(['name' => 'Code Person']);

        $this->get('/admin/users?provider=steam')->assertOk()
            ->assertSee('Steam Person')
            ->assertDontSee('Code Person');

        $this->get('/admin/users?provider=email')->assertOk()
            ->assertSee('Code Person')
            ->assertDontSee('Steam Person');
    }

    public function test_an_account_page_lists_what_that_person_did(): void
    {
        $user = User::factory()->create(['name' => 'Busy Person']);

        $submitted = $this->server(['name' => 'Submitted Server', 'submitted_by_user_id' => $user->id]);
        $claimed = $this->server(['name' => 'Claimed Server', 'user_id' => $user->id, 'claimed_at' => now()]);
        $voted = $this->server(['name' => 'Voted Server']);

        Vote::create([
            'server_id' => $voted->id,
            'user_id' => $user->id,
            'ip_hash' => Vote::hashIp('203.0.113.9'),
            'nickname' => 'busy',
            'vote_day' => now()->toDateString(),
        ]);

        $response = $this->get("/admin/users/{$user->id}")->assertOk();

        $response->assertSee('Submitted Server');
        $response->assertSee('Claimed Server');
        $response->assertSee('Voted Server');
        $this->assertSame($submitted->id, $response->viewData('user')->submissions->first()->id);
        $this->assertSame($claimed->id, $response->viewData('user')->servers->first()->id);
    }

    private function server(array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'game_id' => Game::where('slug', 'minecraft')->value('id'),
            'status' => ServerStatus::Online,
        ]);
    }
}
