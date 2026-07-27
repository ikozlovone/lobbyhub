<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\Monitoring\HostSpread;
use Tests\TestCase;

class HostSpreadTest extends TestCase
{
    private HostSpread $spread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spread = new HostSpread;
    }

    public function test_it_interleaves_servers_of_the_same_provider(): void
    {
        // What the due-servers query hands us: a whole rack, back to back.
        $servers = collect([
            $this->server('10.0.0.1', 'a1'),
            $this->server('10.0.0.1', 'a2'),
            $this->server('10.0.0.1', 'a3'),
            $this->server('10.0.0.2', 'b1'),
            $this->server('10.0.0.2', 'b2'),
            $this->server('10.0.0.3', 'c1'),
        ]);

        $arranged = $this->spread->arrange($servers, maxPerHost: 10);

        $this->assertSame(['a1', 'b1', 'c1', 'a2', 'b2', 'a3'], $arranged->pluck('slug')->all());
    }

    public function test_it_keeps_every_server_when_under_the_cap(): void
    {
        $servers = collect([
            $this->server('10.0.0.1', 'a1'),
            $this->server('10.0.0.2', 'b1'),
        ]);

        $this->assertCount(2, $this->spread->arrange($servers, maxPerHost: 10));
        $this->assertSame(0, $this->spread->heldBack());
    }

    public function test_it_caps_how_many_servers_one_host_gets_per_batch(): void
    {
        $servers = collect([
            $this->server('10.0.0.1', 'a1'),
            $this->server('10.0.0.1', 'a2'),
            $this->server('10.0.0.1', 'a3'),
            $this->server('10.0.0.1', 'a4'),
            $this->server('10.0.0.2', 'b1'),
        ]);

        $arranged = $this->spread->arrange($servers, maxPerHost: 2);

        $this->assertSame(['a1', 'b1', 'a2'], $arranged->pluck('slug')->all());
        // Held back, not dropped: they are still due and get picked up next run.
        $this->assertSame(2, $this->spread->heldBack());
    }

    public function test_it_groups_by_hostname_when_the_ip_is_not_resolved_yet(): void
    {
        $servers = collect([
            $this->server(null, 'a1', 'shared.host.net'),
            $this->server(null, 'a2', 'shared.host.net'),
            $this->server(null, 'b1', 'other.host.net'),
        ]);

        $arranged = $this->spread->arrange($servers, maxPerHost: 10);

        $this->assertSame(['a1', 'b1', 'a2'], $arranged->pluck('slug')->all());
    }

    public function test_a_zero_cap_disables_the_limit(): void
    {
        $servers = collect([
            $this->server('10.0.0.1', 'a1'),
            $this->server('10.0.0.1', 'a2'),
            $this->server('10.0.0.1', 'a3'),
        ]);

        $this->assertCount(3, $this->spread->arrange($servers, maxPerHost: 0));
        $this->assertSame(0, $this->spread->heldBack());
    }

    public function test_an_empty_batch_stays_empty(): void
    {
        $this->assertTrue($this->spread->arrange(collect())->isEmpty());
    }

    private function server(?string $ip, string $slug, string $host = 'example.net'): Server
    {
        return Server::make(['ip_address' => $ip, 'slug' => $slug, 'host' => $host]);
    }
}
