<?php

namespace App\Services\Catalog;

use App\Enums\QueryFailure;
use App\Enums\ServerStatus;
use App\Jobs\QueryServer;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\Exceptions\ServerAlreadyListed;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\Exceptions\UnsupportedProtocol;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Validation\ValidationException;

/**
 * Adding a server by hand — the owner-facing half of discovery.
 *
 * The gate is the same one the whole catalog rests on: we query the address
 * ourselves before writing anything. A form that cannot be reached produces no
 * row at all, which is why the listing never fills up with addresses somebody
 * typed wrong.
 *
 * Unlike discovery, this path has *already measured* the server by the time it
 * writes anything — that is the whole point of the gate. So the measurement is
 * recorded there and then, and the server is listed the moment the form
 * returns. Discovery imports from an index without touching the machine, which
 * is why its rows start unverified and wait for the monitor; a submission has
 * nothing left to wait for.
 *
 * Recording still goes through `QueryServer`, handed the answer we already
 * have. That keeps one implementation of "write down a measurement" — status,
 * cadence, the history sample, geo, the details fetch — instead of a second
 * copy here that would drift from it.
 */
class ServerSubmission
{
    public function __construct(
        private ServerQueryManager $manager,
        private CatalogCounters $counters,
        private FrontendCache $frontend,
    ) {}

    /**
     * @throws ServerAlreadyListed
     * @throws ValidationException
     */
    public function submit(Game $game, string $address, ?int $queryPort = null): Server
    {
        $parsed = ServerAddress::parse($address, $game->default_port)
            ?? $this->reject('address', "Enter the address as host:port — for example 127.0.0.1:{$game->default_port}.");

        // Duplicates are checked before DNS and before the network: an owner
        // whose server discovery already imported should be pointed at their
        // listing, not at a resolver error.
        $existing = $this->listedAt($game, $parsed->host, $parsed->port);
        $this->refuseDuplicate($existing);

        $ip = $this->publicIp($parsed);
        $existing ??= $this->listedAt($game, $ip, $parsed->port);
        $this->refuseDuplicate($existing);

        $result = $this->probe($game, $parsed, $queryPort);

        // A server that was removed and submitted again comes back as itself:
        // its slug is a public URL and its history is worth keeping.
        $server = $existing ?? new Server;

        $server->forceFill([
            'game_id' => $game->id,
            'host' => $parsed->host,
            'port' => $parsed->port,
            'query_port' => $queryPort,
            'ip_address' => $result->ipAddress ?? $ip,
            'deleted_at' => null,
            'is_active' => true,
            // Unknown only for the instant between this save and the recording
            // below, which needs a row to write to. What leaves this method is
            // an online server.
            'status' => ServerStatus::Unknown,
            'players_online' => 0,
            'players_max' => $result->playersMax,
            'next_query_at' => now(),
        ]);

        // Whatever the server told us about itself, without overwriting fields
        // its protocol cannot report.
        foreach ([
            'motd' => $result->motd,
            'map' => $result->map,
            'reported_version' => $result->version,
            'game_port' => $result->gamePort,
            'steam_id' => $result->steamId,
            'wiped_at' => $result->wipedAt,
            'players_queued' => $result->playersQueued,
        ] as $column => $value) {
            if ($value !== null) {
                $server->{$column} = $value;
            }
        }

        if (! $server->exists) {
            $name = trim((string) ($result->motd ?? '')) ?: $parsed->toString();
            $server->name = mb_substr($name, 0, 255);
            $server->slug = Server::slugFor($server->name, $parsed->host, $parsed->port);
        }

        $server->save();

        /*
         * Inline, not queued, and with the answer we already got.
         *
         * Queued, publication depended on a worker being up — and if one was
         * not, the owner's server stayed invisible for as long as that lasted,
         * with nothing on the page to say why. It also meant querying a machine
         * twice in as many seconds to learn the same thing.
         *
         * The cost is that the request now also writes the history sample and
         * fetches the server's details before answering. Both are things the
         * page the submitter is about to land on wants to show.
         */
        QueryServer::dispatchSync($server, $result);

        /*
         * The catalog just gained a member, and every count of it is
         * denormalized — the number beside the game in the sidebar, the three in
         * its hero, the facet chips. All of them are refreshed on a five-minute
         * schedule, which is the right cadence for counts that drift as servers
         * go up and down, and the wrong one for an owner who has just added
         * theirs and gone to look for it.
         */
        $this->counters->refresh();

        // And tell the frontend, whose pages hold both the listing and those
        // counts. Without this the owner waits out a cache window before their
        // server — and the number beside the game — appear.
        $this->frontend->invalidate('games', "game:{$game->slug}", 'servers', "server:{$server->slug}");

        return $server->refresh();
    }

    /**
     * The address has to be reachable from the internet, and it has to be
     * checked before anything connects to it: the FiveM driver speaks HTTP, so
     * an internal hostname here would turn the form into an SSRF probe.
     */
    private function publicIp(ServerAddress $address): string
    {
        $ip = filter_var($address->host, FILTER_VALIDATE_IP)
            ? $address->host
            : gethostbyname($address->host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->reject('address', "Could not resolve {$address->host}. Check the domain, or enter the IP address.");
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->reject('address', 'That address is not reachable from the internet. Enter the public address players connect to.');
        }

        return $ip;
    }

    /**
     * A server already in the catalog at this address, matched on the hostname
     * it was submitted with or on the IP discovery recorded it under.
     * `(game_id, host, port)` is unique, so the same machine reached by domain
     * and by IP has to be caught here rather than by the database.
     */
    private function listedAt(Game $game, string $host, int $port): ?Server
    {
        return Server::withTrashed()
            ->where('game_id', $game->id)
            ->where('port', $port)
            ->where(fn ($query) => $query->where('host', $host)->orWhere('ip_address', $host))
            ->first();
    }

    /**
     * @throws ServerAlreadyListed
     */
    private function refuseDuplicate(?Server $existing): void
    {
        if ($existing !== null && ! $existing->trashed()) {
            throw new ServerAlreadyListed($existing);
        }
    }

    /**
     * The verification itself. Nothing is persisted for this — the driver only
     * needs an address and a protocol, both of which an unsaved model carries.
     */
    private function probe(Game $game, ServerAddress $address, ?int $queryPort): QueryResult
    {
        $probe = new Server([
            'game_id' => $game->id,
            'host' => $address->host,
            'port' => $address->port,
            'query_port' => $queryPort,
        ]);

        $probe->setRelation('game', $game);

        try {
            return $this->manager->for($probe)->query($probe);
        } catch (UnsupportedProtocol) {
            $this->reject('address', "{$game->name} servers cannot be added yet — we have no monitor for this game.");
        } catch (QueryFailed $failure) {
            $this->reject('address', $this->explain($failure, $game, $address, $queryPort));
        }
    }

    /**
     * Turn a failed query into something the person at the form can act on.
     *
     * The old text said one thing for every failure: check the query port. That
     * is the right advice about half the time and actively misleading the rest —
     * it was sending owners to look at a port that had demonstrably accepted the
     * connection. These four cases point at four different places.
     */
    private function explain(QueryFailed $failure, Game $game, ServerAddress $address, ?int $queryPort): string
    {
        $port = $queryPort ?? $address->port;
        $where = "{$address->host}:{$port}";

        // Worth repeating only where a second port is actually in play.
        $hint = $queryPort === null && $game->default_query_port !== null
            ? ' If your query port differs from the game port, enter it in the second field.'
            : '';

        return match ($failure->reason) {
            QueryFailure::Unresolvable => "Could not resolve {$address->host}. Check the domain, or enter the IP address instead.",

            QueryFailure::Unreachable => "Nothing is listening on {$where}. Check that the server is running and that the port is open to the internet.".$hint,

            // The interesting one, and the reason this method exists.
            QueryFailure::Silent => $game->query_protocol->isConnectionOriented()
                ? "{$where} accepted the connection but did not answer a status request. The port is open, so this is not a port problem: check that status queries are enabled on the server, and that your host's DDoS protection is not dropping them — some filters refuse addresses they do not recognise."
                : "No answer from {$where}. Check that the server is running and that the query port is open to the internet.".$hint,

            QueryFailure::Malformed => "{$where} answered, but not in a way we could read. If the address points at a proxy or a web panel rather than the game server itself, use the game server's own address.",
        };
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
