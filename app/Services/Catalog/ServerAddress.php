<?php

namespace App\Services\Catalog;

/**
 * The one thing a submission form asks for: `host:port`.
 *
 * People paste whatever their client shows them — `connect 1.2.3.4:28015`,
 * `steam://connect/1.2.3.4`, `http://host:30120/`, a bare domain — so the
 * parser is deliberately forgiving about the wrapping and strict about what
 * comes out of it.
 */
final readonly class ServerAddress
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    public static function parse(string $input, int $defaultPort): ?self
    {
        $value = self::unwrap($input);

        if ($value === '') {
            return null;
        }

        // IPv6 is only unambiguous in brackets: `[2001:db8::1]:28015`.
        if (str_starts_with($value, '[')) {
            if (! preg_match('/^\[([0-9a-f:.]+)](?::(\d{1,5}))?$/i', $value, $matches)) {
                return null;
            }

            return self::make($matches[1], $matches[2] ?? null, $defaultPort, ipv6: true);
        }

        // More than one colon and no brackets means a bare IPv6 literal: the
        // last group is part of the address, not a port.
        if (substr_count($value, ':') > 1) {
            return self::make($value, null, $defaultPort, ipv6: true);
        }

        [$host, $port] = array_pad(explode(':', $value, 2), 2, null);

        return self::make((string) $host, $port, $defaultPort);
    }

    public function toString(): string
    {
        return (str_contains($this->host, ':') ? "[{$this->host}]" : $this->host).':'.$this->port;
    }

    private static function make(string $host, ?string $port, int $defaultPort, bool $ipv6 = false): ?self
    {
        $host = strtolower(trim($host));

        if ($port !== null && ! ctype_digit($port)) {
            return null;
        }

        $port = $port === null ? $defaultPort : (int) $port;

        if ($port < 1 || $port > 65535) {
            return null;
        }

        $valid = $ipv6
            ? (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            : filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || self::isHostname($host);

        return $valid ? new self($host, $port) : null;
    }

    private static function unwrap(string $input): string
    {
        $value = trim($input);
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value) ?? '';
        // `connect 1.2.3.4:28015` from the game console, `connect/…` from a
        // steam:// link — the same word arrives both ways.
        $value = preg_replace('#^connect[\s/]+#i', '', $value) ?? '';
        $value = explode('/', $value, 2)[0];

        return trim($value);
    }

    /**
     * A dot is required on purpose: it rules out `localhost` and single-label
     * intranet names, which are never a server anyone else can join.
     */
    private static function isHostname(string $host): bool
    {
        return (bool) preg_match(
            '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $host,
        );
    }
}
