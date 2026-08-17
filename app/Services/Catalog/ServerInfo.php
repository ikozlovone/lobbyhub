<?php

namespace App\Services\Catalog;

use App\Models\Server;

/**
 * Turns the raw key/value rules a server publishes into a stable, labelled
 * shape the frontend can render without knowing anything about Rust, Source or
 * whatever comes next.
 *
 * Unknown keys are dropped rather than passed through: the server card is a
 * product surface, not a protocol dump.
 */
class ServerInfo
{
    public function for(Server $server): array
    {
        $rules = $server->details ?? [];

        return array_filter([
            'mode' => $this->string($rules, 'gmt'),
            'map_size' => $this->int($rules, 'world.size'),
            'map_seed' => $this->int($rules, 'world.seed'),
            'entities' => $this->int($rules, 'ent_cnt'),
            'fps' => $this->int($rules, 'fps'),
            'fps_average' => $this->float($rules, 'fps_avg'),
            'pve' => $this->bool($rules, 'pve'),
            'build' => $this->string($rules, 'build'),
            // Seconds since the server process started. Rust restarts on wipe,
            // so this is a second opinion on `wiped_at`, not a duplicate of it.
            'uptime_seconds' => $this->int($rules, 'uptime'),
            // A hostname the owner prefers players use instead of the raw IP.
            'connect_hostname' => $this->string($rules, 'favendpoint'),
        ], fn ($value) => $value !== null);
    }

    /** Images the server itself publishes — banner, logo, generated map. */
    public function media(Server $server): array
    {
        $rules = $server->details ?? [];

        return array_filter([
            'banner' => $this->url($rules, 'headerimage'),
            'logo' => $this->url($rules, 'logoimage'),
            'map_image' => $this->url($rules, 'map_image_url'),
            'map_file' => $this->url($rules, 'level_url'),
        ], fn ($value) => $value !== null);
    }

    /** The server's own description, when it publishes one. */
    public function description(Server $server): ?string
    {
        return $this->string($server->details ?? [], 'description');
    }

    public function website(Server $server): ?string
    {
        return $this->url($server->details ?? [], 'url');
    }

    private function string(array $rules, string $key): ?string
    {
        $value = $rules[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function int(array $rules, string $key): ?int
    {
        $value = $this->string($rules, $key);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function float(array $rules, string $key): ?float
    {
        $value = $this->string($rules, $key);

        return $value !== null && is_numeric($value) ? round((float) $value, 1) : null;
    }

    private function bool(array $rules, string $key): ?bool
    {
        $value = $this->string($rules, $key);

        return $value === null ? null : in_array(mb_strtolower($value), ['true', '1', 'yes'], true);
    }

    /**
     * Only http(s) links on a real hostname are surfaced. These strings come
     * from server owners, so they are untrusted input that ends up either in
     * an href or as an upstream fetched by our image proxy — and a raw IP is
     * how an owner would point either at 127.0.0.1 or a private subnet the
     * proxy has no business talking to. Legitimate images live on domains
     * (CDNs, imgur, Steam) so blocking IP hosts costs nothing real.
     */
    private function url(array $rules, string $key): ?string
    {
        $value = $this->string($rules, $key);

        if ($value === null || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $value;
    }
}
