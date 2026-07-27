<?php

namespace Tests\Feature;

use App\Enums\QueryProtocol;
use App\Models\Game;
use App\Models\Server;
use App\Services\Monitoring\Drivers\FiveMQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiveMQueryDriverTest extends TestCase
{
    private FiveMQueryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FiveMQueryDriver;
    }

    public function test_it_reads_a_typical_dynamic_json(): void
    {
        $result = $this->driver->parseDynamic(json_encode([
            'clients' => 128,
            'gametype' => 'Roleplay',
            'hostname' => 'Los Santos Roleplay',
            'iv' => '1234567',
            'mapname' => 'San Andreas',
            'sv_maxclients' => 256,
        ]), latencyMs: 62, ip: '5.6.7.8');

        $this->assertSame(128, $result->playersOnline);
        $this->assertSame(256, $result->playersMax);
        $this->assertSame('San Andreas', $result->map);
        $this->assertSame('Los Santos Roleplay', $result->motd);
        $this->assertSame(62, $result->latencyMs);
        $this->assertSame('5.6.7.8', $result->ipAddress);
        // The build number lives in info.json, which we deliberately do not fetch.
        $this->assertNull($result->version);
    }

    public function test_it_strips_the_colour_codes_fivem_hostnames_are_full_of(): void
    {
        $result = $this->driver->parseDynamic(json_encode([
            'clients' => 1,
            'sv_maxclients' => 64,
            'hostname' => '^2[EU] ^7Grand ^1RP ^7| ~r~whitelist~s~ open',
        ]));

        $this->assertSame('[EU] Grand RP | whitelist open', $result->motd);
    }

    public function test_it_accepts_the_older_maxclients_key(): void
    {
        $result = $this->driver->parseDynamic(json_encode([
            'clients' => 5,
            'maxclients' => 32,
        ]));

        $this->assertSame(32, $result->playersMax);
    }

    /**
     * Some hosts run an unrelated web server on 30120. Without this check it
     * would be recorded as a healthy but empty FiveM server.
     */
    public function test_it_rejects_a_response_that_is_not_a_fivem_endpoint(): void
    {
        $this->expectException(QueryFailed::class);
        $this->expectExceptionMessage('no player counts');

        $this->driver->parseDynamic(json_encode(['status' => 'ok']));
    }

    public function test_it_rejects_a_non_json_body(): void
    {
        $this->expectException(QueryFailed::class);

        $this->driver->parseDynamic('<html>nginx</html>');
    }

    public function test_it_queries_the_dynamic_endpoint_over_http(): void
    {
        Http::fake([
            '*/dynamic.json' => Http::response([
                'clients' => 42,
                'sv_maxclients' => 128,
                'hostname' => 'Test RP',
                'mapname' => 'San Andreas',
            ]),
        ]);

        $result = $this->driver->query($this->fivemServer('127.0.0.1'));

        $this->assertSame(42, $result->playersOnline);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), ':30120/dynamic.json'));
        // One request per poll: FiveM must not cost more than the other games.
        Http::assertSentCount(1);
    }

    public function test_a_privacy_protected_server_counts_as_unreachable(): void
    {
        // sv_endpointPrivacy hides these endpoints behind a 403.
        Http::fake(['*' => Http::response('', 403)]);

        $this->expectException(QueryFailed::class);
        $this->expectExceptionMessage('HTTP 403');

        $this->driver->query($this->fivemServer('127.0.0.1'));
    }

    public function test_a_connection_failure_is_reported_as_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->expectException(QueryFailed::class);
        $this->expectExceptionMessage('Could not connect');

        $this->driver->query($this->fivemServer('127.0.0.1'));
    }

    public function test_the_manager_now_resolves_every_protocol_we_store(): void
    {
        $manager = app(ServerQueryManager::class);

        foreach (QueryProtocol::cases() as $protocol) {
            $this->assertTrue($manager->supports($protocol), "{$protocol->value} has no driver");
        }

        $this->assertInstanceOf(FiveMQueryDriver::class, $manager->driver(QueryProtocol::FiveM));
    }

    private function fivemServer(string $host): Server
    {
        $server = Server::make(['host' => $host, 'port' => 30120, 'query_port' => null]);
        $server->setRelation('game', Game::make([
            'slug' => 'fivem',
            'default_port' => 30120,
            'query_protocol' => QueryProtocol::FiveM,
        ]));

        return $server;
    }
}
