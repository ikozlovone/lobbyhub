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
}