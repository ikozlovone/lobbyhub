<?php

namespace App\Services\Catalog;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * A pasted list of addresses, turned into catalog rows.
 *
 * Deliberately *unlike* the public submission form, in the one way that
 * matters: nothing is queried here. The form probes an address before it will
 * write a row, which is what keeps strangers from filling the catalog with
 * typos — and which costs a five-second timeout for every address that does not
 * answer. Pasting five hundred of those would be a forty-minute request that
 * ends in a gateway timeout with nothing saved.
 *
 * So this path writes candidates the way discovery does — status `unknown`,
 * invisible to every public listing — and lets the monitor do the verifying.
 * Rows enter the catalog only when our own query reaches them, which is the
 * same gate as before, just moved off the request.
 *
 * The trade is that a mistyped address here becomes a row that sits unverified
 * instead of an error message. It never reaches a listing, and it backs off to
 * six-hourly within an hour, but it is in the table. That is the price of
 * accepting an unbounded paste, and it is why this screen is behind /admin.
 */
class BulkServerImport
{
    /**
     * Separators accepted between the address and its query port.
     *
     * More than one because there is no convention to follow: control panels
     * export pipes, spreadsheets export commas and tabs, and people typing by
     * hand use a space. Rejecting four of the five would be a parser that is
     * strict for no reason anybody could guess.
     *
     * A second colon is *not* among them: `1.2.3.4:28015:28016` cannot be told
     * apart from an IPv6 literal without knowing which one was meant.
     */
    private const SEPARATORS = '|;,\t ';

    public function __construct(
        private CatalogCounters $counters,
        private FrontendCache $frontend,
    ) {}

    public function import(Game $game, string $input): BulkImportReport
    {
        $report = new BulkImportReport;

        foreach ($this->lines($input) as $number => $line) {
            $this->line($game, $line, $number, $report);
        }

        if ($report->added > 0) {
            // The sidebar count and the game hero are denormalised, and an
            // import that does not move them looks like it did nothing. Cheap
            // enough to run once per import; never once per line.
            $this->counters->refresh();
            $this->frontend->invalidate('games', "game:{$game->slug}", 'servers');
        }

        return $report;
    }

    private function line(Game $game, string $line, int $number, BulkImportReport $report): void
    {
        [$addressPart, $queryPort] = $this->split($line);

        if ($queryPort === false) {
            $report->reject($number, $line, 'Query port must be a number between 1 and 65535.');

            return;
        }

        $parsed = ServerAddress::parse($addressPart, $game->default_port);

        if ($parsed === null) {
            $report->reject($number, $line, "Could not read an address. Use host:port — for example 127.0.0.1:{$game->default_port}.");

            return;
        }

        // Matched the way the submission form matches: on the hostname as typed
        // or on the IP discovery recorded, because the same machine reached
        // both ways is one server and the unique index cannot see that.
        $existing = Server::withTrashed()
            ->where('game_id', $game->id)
            ->where('port', $parsed->port)
            ->where(fn ($query) => $query->where('host', $parsed->host)->orWhere('ip_address', $parsed->host))
            ->first();

        if ($existing !== null && ! $existing->trashed()) {
            // Not an error worth stopping for: pasting a list that overlaps one
            // already imported is the normal way this screen gets used twice.
            $report->skip($number, $parsed->toString(), $existing->slug);

            return;
        }

        $host = $parsed->toString();

        if ($existing !== null) {
            // A server that was removed and pasted back comes back as itself —
            // its slug is a public URL and its history is worth keeping.
            $existing->forceFill([
                'query_port' => $queryPort,
                'deleted_at' => null,
                'is_active' => true,
                'next_query_at' => now(),
                // Back to the front of the queue with the rest of this import:
                // see DispatchServerQueries, which serves never-queried rows
                // first, and this is how a restored row rejoins them.
                'last_queried_at' => null,
                'status' => ServerStatus::Unknown,
            ])->save();

            $report->restored($number, $host, $existing->slug);

            return;
        }

        $server = Server::create([
            'game_id' => $game->id,
            'host' => $parsed->host,
            'port' => $parsed->port,
            'query_port' => $queryPort,
            // No DNS here. Resolving a pasted list would be one blocking lookup
            // per line against a resolver we do not control; the monitor fills
            // this in from the query it is about to make anyway.
            'ip_address' => filter_var($parsed->host, FILTER_VALIDATE_IP) ?: null,
            // Until the monitor reaches it there is nothing to call this server
            // but its address, and inventing a friendlier name would be
            // inventing a fact. The first successful query overwrites it.
            'name' => $host,
            // Empty name on purpose: slugFor would otherwise prefix the address
            // to itself and mint `451521612028015-45-152-161-20-28015`. This is
            // a placeholder either way — the first successful query replaces
            // both the name and the slug, and until then the row is invisible.
            'slug' => Server::slugFor('', $parsed->host, $parsed->port),
            'status' => ServerStatus::Unknown,
            'players_online' => 0,
            'players_max' => 0,
            'next_query_at' => now(),
        ]);

        $report->add($number, $host, $server->slug);
    }

    /**
     * Split a line into its address and an optional query port.
     *
     * Returns `false` for the port when something was given in its place that
     * is not a port, so a fat-fingered line is reported rather than silently
     * imported without the port it was meant to carry.
     *
     * @return array{0: string, 1: int|null|false}
     */
    private function split(string $line): array
    {
        $parts = preg_split('/['.self::SEPARATORS.']+/', trim($line), 2, PREG_SPLIT_NO_EMPTY);

        $address = $parts[0] ?? '';
        $tail = isset($parts[1]) ? trim($parts[1]) : '';

        if ($tail === '') {
            return [$address, null];
        }

        if (! ctype_digit($tail) || (int) $tail < 1 || (int) $tail > 65535) {
            return [$address, false];
        }

        return [$address, (int) $tail];
    }

    /**
     * Lines, numbered as the person pasting them sees them.
     *
     * Blank lines and `#` comments are dropped so a list annotated in a text
     * editor can be pasted whole, but the numbering counts them — a report
     * pointing at line 12 has to mean the twelfth line in the box.
     *
     * @return Collection<int, string>
     */
    private function lines(string $input): Collection
    {
        return collect(preg_split('/\R/', $input) ?: [])
            ->map(fn (string $line) => trim($line))
            // ->filter() keeps keys, which is what makes the numbering survive.
            ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#'))
            ->mapWithKeys(fn (string $line, int $index) => [$index + 1 => $line]);
    }
}
