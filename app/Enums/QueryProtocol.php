<?php

namespace App\Enums;

enum QueryProtocol: string
{
    /** Minecraft Server List Ping (TCP handshake + status request). */
    case Minecraft = 'minecraft';

    /** Valve A2S over UDP: Rust, ARK, CS2, Garry's Mod, 7 Days to Die. */
    case Source = 'source';

    /** FiveM / CFX HTTP endpoints: /info.json, /players.json, /dynamic.json. */
    case FiveM = 'fivem';

    public function label(): string
    {
        return match ($this) {
            self::Minecraft => 'Minecraft Server List Ping',
            self::Source => 'Valve A2S',
            self::FiveM => 'FiveM HTTP',
        };
    }

    /**
     * Whether silence is informative.
     *
     * TCP and HTTP have a handshake, so getting in and hearing nothing tells us
     * something is listening and chose not to answer. A2S is UDP: there is no
     * connection to accept, and silence covers a closed port, a firewall and a
     * server that simply ignored us. Only the first kind is worth explaining.
     */
    public function isConnectionOriented(): bool
    {
        return $this !== self::Source;
    }
}
