<?php

namespace App\Services\Monitoring;

use App\Enums\QueryProtocol;
use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Drivers\MinecraftQueryDriver;
use App\Services\Monitoring\Drivers\SourceQueryDriver;
use App\Services\Monitoring\Exceptions\UnsupportedProtocol;
use Illuminate\Contracts\Container\Container;

class ServerQueryManager
{
    public function __construct(private Container $container) {}

    public function for(Server $server): ServerQueryDriver
    {
        return $this->driver($server->game->query_protocol);
    }

    /**
     * @throws UnsupportedProtocol
     */
    public function driver(QueryProtocol $protocol): ServerQueryDriver
    {
        return $this->container->make(match ($protocol) {
            QueryProtocol::Minecraft => MinecraftQueryDriver::class,
            QueryProtocol::Source => SourceQueryDriver::class,
            // The FiveM driver is not built yet.
            default => throw UnsupportedProtocol::for($protocol),
        });
    }

    public function supports(QueryProtocol $protocol): bool
    {
        return match ($protocol) {
            QueryProtocol::Minecraft, QueryProtocol::Source => true,
            default => false,
        };
    }
}
