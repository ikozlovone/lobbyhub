<?php

namespace App\Services\Discovery;

/**
 * One EOS matchmaking session, normalized onto the same vocabulary as
 * DiscoveredServer so downstream sync code can treat both alike.
 *
 * The wire shape of a session is a nested envelope of typed attributes
 * (`ADDRESS_s`, `GAMEPORT_l`, `MAPNAME_s`, …) — Epic's suffix names the
 * declared type of the value, and the same key with a different suffix is a
 * different attribute. Reading them by hand is a hazard: `PLAYERS_l` is not
 * where the count lives (that is on the session envelope itself as
 * `totalPlayers`), and half the fields are absent on half the sessions. So
 * everything the sync needs is lifted here into a flat, typed record, and
 * anything unknown to us is dropped.
 *
 * Two addresses live in one session, and both matter: `ADDRESS_s` is the IP
 * the game server hands to a joining client, and `GAMEPORT_l` is the UDP port
 * that goes with it. ASA speaks over EOS SDR internally, so there is no
 * separate query port to record — the pair below is what the client dials and
 * what the catalog stores under.
 */
final readonly class DiscoveredEosServer
{
    public function __construct(
        public string $ip,
        public int $port,
        public string $name,
        public int $playersOnline,
        public int $playersMax,
        public ?string $map,
        public ?string $version,
        public ?string $sessionId,
    ) {}

    /**
     * The `ip:port` a raw row answers to, without allocating the record.
     *
     * Called from the sweep's dedup loop before anything else is decided: on
     * a game with a dozen thousand sessions, ninety percent of the rows are
     * dropped by the caller's address filter and there is no reason to parse
     * a name or a map for one of them.
     *
     * @param  array<string, mixed>  $session
     * @return array{0: string, 1: int}|null
     */
    public static function addressOf(array $session): ?array
    {
        $attrs = self::attributes($session);

        $ip = trim((string) ($attrs['ADDRESS_s'] ?? ''));
        $port = (int) ($attrs['GAMEPORT_l'] ?? 0);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            return null;
        }

        return [$ip, $port];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromApi(array $session): ?self
    {
        $address = self::addressOf($session);

        if ($address === null) {
            return null;
        }

        [$ip, $port] = $address;
        $attrs = self::attributes($session);

        // Two candidates, in the order ARK: SA fills them. `CUSTOMSERVERNAME_s`
        // is what an operator typed in the config; `SESSIONNAME_s` is the
        // default name the game manufactured. Prefer the human one when both
        // exist, and fall back to the address so nothing ever comes out empty.
        $name = trim((string) ($attrs['CUSTOMSERVERNAME_s'] ?? $attrs['SESSIONNAME_s'] ?? ''));
        if ($name === '') {
            $name = $ip.':'.$port;
        }

        /*
         * Players and max both live in slightly different fields depending on
         * game and EOS SDK version:
         *
         *  - `totalPlayers` on the envelope is the current count in every
         *    session I have looked at; it is the reliable one.
         *  - Max is either `settings.maxPublicPlayers`, `totalPlayers +
         *    openPublicPlayers`, or in an attribute like `NUMOPENPUBCONN_l`.
         *
         * Try the reliable pair first; fall through to the sum, which needs
         * both halves to make sense.
         */
        $playersOnline = max(0, (int) ($session['totalPlayers'] ?? 0));

        $maxFromSettings = (int) ($session['settings']['maxPublicPlayers'] ?? 0);
        $openSlots = (int) ($session['openPublicPlayers'] ?? 0);

        if ($maxFromSettings > 0) {
            $playersMax = $maxFromSettings;
        } elseif ($openSlots > 0 || $playersOnline > 0) {
            $playersMax = $playersOnline + $openSlots;
        } else {
            $playersMax = 0;
        }

        $map = self::clean((string) ($attrs['MAPNAME_s'] ?? ''));

        // Version is a keyword in ASA (`v93.12`, `v82.0`) and lives in the
        // session name as well; the attribute is the honest source.
        $version = self::clean((string) ($attrs['BUILDID_s'] ?? $attrs['SERVERVERSION_s'] ?? ''));

        $sessionId = self::clean((string) ($session['id'] ?? ''));

        return new self(
            ip: $ip,
            port: $port,
            name: mb_substr($name, 0, 255),
            playersOnline: $playersOnline,
            playersMax: $playersMax,
            map: $map,
            version: $version,
            sessionId: $sessionId,
        );
    }

    /**
     * The attributes block, guarded against both shapes it arrives in.
     *
     * `attributes` is what the docs show; some EOS regions wrap it in
     * `attributes.attributes` or send it as `sessionAttributes`. Reading them
     * all here lets the rest of the class deal with one dictionary.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private static function attributes(array $session): array
    {
        if (isset($session['attributes']) && is_array($session['attributes'])) {
            $attrs = $session['attributes'];

            // Nested one level, seen from Epic's EU edge on some responses.
            if (isset($attrs['attributes']) && is_array($attrs['attributes'])) {
                return $attrs['attributes'];
            }

            return $attrs;
        }

        if (isset($session['sessionAttributes']) && is_array($session['sessionAttributes'])) {
            return $session['sessionAttributes'];
        }

        return [];
    }

    private static function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
