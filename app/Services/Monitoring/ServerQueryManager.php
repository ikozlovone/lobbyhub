<?php

namespace App\Services\Monitoring;

use App\Enums\QueryProtocol;
use App\Models\Server;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Drivers\FiveMQueryDriver;
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
        $driver = $this->driverClass($protocol);

        if ($driver === null) {
            throw UnsupportedProtocol::for($protocol);
        }

        return $this->container->make($driver);
    }

    /** Lets the dispatcher skip a game instead of failing it every cycle. */
    public function supports(QueryProtocol $protocol): bool
    {
        return $this->driverClass($protocol) !== null;
    }

    /**
     * `default` is deliberate rather than an exhaustive match: adding a protocol
     * to the enum before its driver exists should make that game unsupported,
     * not throw an UnhandledMatchError from inside a queued job.
     *
     * @return class-string<ServerQueryDriver>|null
     */
    private function driverClass(QueryProtocol $protocol): ?string
    {
        return match ($protocol) {
            QueryProtocol::Minecraft => MinecraftQueryDriver::class,
            QueryProtocol::Source => SourceQueryDriver::class,
            QueryProtocol::FiveM => FiveMQueryDriver::class,
            default => null,
        };
    }
}
