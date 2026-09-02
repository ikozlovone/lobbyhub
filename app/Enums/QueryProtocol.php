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

    /**
     * Epic Online Services matchmaking, one bulk pull per deployment.
     *
     * Not a per-server driver — EOS-only games (ARK: Survival Ascended is the
     * one today) never register with Steam Master and do not answer A2S on any
     * port, so the poller has nothing to send them. Their state is pulled by
     * `eos:sync` from `POST /matchmaking/v1/{deployment}/filter`, which returns
     * every live session at once. Marked here so ServerQueryManager reports the
     * game as unsupported by the UDP path (skipped cleanly by the dispatcher)
     * rather than throwing UnhandledMatchError inside a queued job.
     */
    case Eos = 'eos';

    public function label(): string
    {
        return match ($this) {
            self::Minecraft => 'Minecraft Server List Ping',
            self::Source => 'Valve A2S',
            self::FiveM => 'FiveM HTTP',
            self::Eos => 'Epic Online Services',
        };
    }

    /**
     * Whether silence is informative.
     *
     * TCP and HTTP have a handshake, so getting in and hearing nothing tells us
     * something is listening and chose not to answer. A2S is UDP: there is no
     * connection to accept, and silence covers a closed port, a firewall and a
     * server that simply ignored us. Only the first kind is worth explaining.
     *
     * EOS has no per-server transport at all — the sweep replaces the driver
     * outright — so the question does not apply, and answering either way would
     * mislead a caller trying to reason about a UDP timeout.
     */
    public function isConnectionOriented(): bool
    {
        return $this !== self::Source;
    }
}
