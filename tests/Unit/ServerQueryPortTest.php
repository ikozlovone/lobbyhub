<?php

namespace Tests\Unit;

use App\Models\Game;
use App\Models\Server;
use Tests\TestCase;

class ServerQueryPortTest extends TestCase
{
    public function test_it_queries_the_servers_own_port_by_default(): void
    {
        // The bug this covers: falling back to the game default (28015) would
        // query the wrong port and mark a healthy server offline.
        $server = $this->server(port: 28020, queryPort: null);

        $this->assertSame(28020, $server->queryPort());
    }

    public function test_an_explicit_query_port_wins(): void
    {
        $server = $this->server(port: 28020, queryPort: 28017);

        $this->assertSame(28017, $server->queryPort());
    }

    public function test_the_connect_address_prefers_the_reported_game_port(): void
    {
        $server = $this->server(port: 28015, queryPort: null);
        $server->game_port = 28010;

        $this->assertSame('rust.example.net:28010', $server->address());
    }

    public function test_the_connect_address_falls_back_to_the_submitted_port(): void
    {
        $this->assertSame('rust.example.net:28015', $this->server(port: 28015, queryPort: null)->address());
    }

    private function server(int $port, ?int $queryPort): Server
    {
        $server = Server::make([
            'host' => 'rust.example.net',
            'port' => $port,
            'query_port' => $queryPort,
        ]);

        $server->setRelation('game', Game::make(['default_port' => 28015, 'default_query_port' => null]));

        return $server;
    }
}
