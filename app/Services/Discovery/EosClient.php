<?php

namespace App\Services\Discovery;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads Epic Online Services matchmaking, which is how games with no Steam
 * server registration expose their live sessions.
 *
 * Two endpoints and one credential triple per game:
 *
 *  - `POST /auth/v1/oauth/token` exchanges a `client_credentials` Basic-auth
 *    grant for a bearer token, scoped to a deployment; the token lives about
 *    two hours and is cached in-process so a walk of forty pages is one token
 *    fetch, not forty-one.
 *  - `POST /matchmaking/v1/{deployment}/filter` returns a page of live
 *    sessions matching the criteria in the body. There is no `GET` form
 *    because criteria are structured and would not fit on a query string.
 *
 * Paging is by `{count, offset}` inside the request body. Epic caps `maxResults`
 * per page — measured 200 as the working upper bound; asking for more comes back
 * with 400. `pagination.totalCount` in the response is the natural signal to
 * stop: `offset >= totalCount` means the whole population has been walked. A
 * short page falls out of that same test.
 *
 * Nothing here talks to the database. The client's job stops at "one game's
 * list of sessions"; deduping, normalising and writing are downstream.
 */
class EosClient
{
    /**
     * When to renew the token relative to its declared TTL.
     *
     * Not `expires_in` itself: a token that expires this exact second reaches
     * the next page as a 401, and Epic's front is not consistent about giving
     * that as a fresh 401 rather than a retryable 5xx. A minute of headroom is
     * cheap — the token is refreshed maybe once per hour anyway — and stops the
     * whole class of "walk died on page 37 of 42" that a hard-boundary refresh
     * produced.
     */
    private const TOKEN_HEADROOM = 60;

    /** @var array<string, array{token: string, expiresAt: int}> keyed by deployment id */
    private array $tokens = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $pauseMs,
        private readonly int $attempts,
        private readonly int $pageSize,
    ) {}

    /**
     * Every page of one deployment's sessions, in order.
     *
     * Yields the sessions of a page as it lands, so a game with thirty thousand
     * live servers is never held in memory in one piece. Stops on the first
     * empty page or when `totalCount` says the walk is done, whichever hits
     * first — the two rules cover the case where a session leaves during a
     * long walk and the last page comes back short.
     *
     * `maxPages` is a cap for `--pages`, a small-batch flag on the command so
     * an operator watching the first minute of a first sweep is not paying for
     * the last thirty; null takes the whole population.
     *
     * @param  array<int, array<string, mixed>>  $criteria  filter body, empty means "everything"
     * @return Generator<int, list<array<string, mixed>>>  one yield per page of sessions
     */
    public function pages(EosDeployment $deployment, array $criteria = [], ?int $maxPages = null, int $startOffset = 0): Generator
    {
        $offset = $startOffset;
        $pages = 0;

        while ($maxPages === null || $pages < $maxPages) {
            $payload = $this->filter($deployment, $criteria, $offset);
            $sessions = $payload['sessions'] ?? [];

            // A short page is the end of the walk. Guarded before the yield
            // so the caller does not also have to check.
            if ($sessions === []) {
                return;
            }

            yield $sessions;
            $pages++;

            $offset += count($sessions);

            /*
             * Two stop conditions, both applied. `totalCount` is the honest one
             * — Epic knows how many matches there are — but it is missing from
             * the response often enough that the count-based check has to hold
             * on its own. A page shorter than requested is the fallback: the
             * next filter would ask for rows past the end, which either comes
             * back empty or with an off-by-one duplicate depending on Epic's
             * mood.
             */
            $total = (int) ($payload['pagination']['totalCount'] ?? 0);

            if ($total > 0 && $offset >= $total) {
                return;
            }

            if (count($sessions) < $this->pageSize) {
                return;
            }

            // Politeness between pages of a walk that may run to dozens of
            // requests. The token cache means the next page is one round trip,
            // not two.
            if ($this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        }
    }

    /**
     * One page of a deployment's session list.
     *
     * Public so a `--dry-run` on the command can pull a single page without
     * setting up a generator or a sync — it is the smallest useful thing this
     * client does, and returning the raw envelope (not just the sessions) lets
     * the caller print `totalCount` alongside.
     *
     * @param  array<int, array<string, mixed>>  $criteria
     * @return array{sessions: list<array<string, mixed>>, pagination?: array<string, mixed>}
     */
    public function filter(EosDeployment $deployment, array $criteria, int $offset): array
    {
        $token = $this->token($deployment);

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 15))
            ->retry($this->attempts, fn (int $attempt) => $attempt * 1000, throw: false)
            ->post(
                $this->baseUrl."/matchmaking/v1/{$deployment->deploymentId}/filter",
                [
                    'criteria' => $criteria,
                    'maxResults' => $this->pageSize,
                    'pagination' => ['count' => $this->pageSize, 'offset' => $offset],
                ],
            );

        // A 401 here is the token cache reading a stale entry — Epic can retire
        // a token before its declared TTL if their front rotates keys. Drop the
        // cache once and let the next call mint a fresh one; a second 401 is a
        // real credential problem and is not swept away by a retry.
        if ($response->status() === 401 && isset($this->tokens[$deployment->deploymentId])) {
            unset($this->tokens[$deployment->deploymentId]);
            $token = $this->token($deployment);

            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout($this->timeout)
                ->connectTimeout(min($this->timeout, 15))
                ->retry($this->attempts, fn (int $attempt) => $attempt * 1000, throw: false)
                ->post(
                    $this->baseUrl."/matchmaking/v1/{$deployment->deploymentId}/filter",
                    [
                        'criteria' => $criteria,
                        'maxResults' => $this->pageSize,
                        'pagination' => ['count' => $this->pageSize, 'offset' => $offset],
                    ],
                );
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'EOS matchmaking returned HTTP %d at offset %d for deployment %s',
                $response->status(),
                $offset,
                $this->redact($deployment, $response->body()),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('EOS matchmaking returned a non-JSON body');
        }

        /*
         * Two shapes seen in the wild depending on which region of Epic's edge
         * answered: `{sessions: [...], pagination: {...}}` and the same fields
         * wrapped in `{response: {...}}`. Handling both here rather than in the
         * sweep keeps the walk one interface.
         */
        if (isset($body['response']) && is_array($body['response'])) {
            $body = $body['response'];
        }

        return [
            'sessions' => is_array($body['sessions'] ?? null) ? array_values($body['sessions']) : [],
            'pagination' => is_array($body['pagination'] ?? null) ? $body['pagination'] : [],
        ];
    }

    /**
     * A bearer token for one deployment, minted the first time and refreshed
     * a minute before it expires.
     *
     * OAuth `client_credentials`: Basic-auth the client id and secret, hand
     * back `deployment_id` in the form body, receive `{access_token, expires_in,
     * token_type: "bearer"}`. Cached per deployment so a run over three EOS
     * games does not spend three token fetches on the first page of each.
     *
     * The cache lives in this instance. The client is injected as a singleton
     * from the container, so a long-running worker keeps its tokens hot; a
     * short CLI mints one and throws it away, which is what a one-off sync
     * command wants anyway.
     */
    private function token(EosDeployment $deployment): string
    {
        $cached = $this->tokens[$deployment->deploymentId] ?? null;

        if ($cached !== null && $cached['expiresAt'] > time() + self::TOKEN_HEADROOM) {
            return $cached['token'];
        }

        $response = Http::asForm()
            ->withBasicAuth($deployment->clientId, $deployment->clientSecret)
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 15))
            ->retry($this->attempts, fn (int $attempt) => $attempt * 1000, throw: false)
            ->post($this->baseUrl.'/auth/v1/oauth/token', [
                'grant_type' => 'client_credentials',
                'deployment_id' => $deployment->deploymentId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'EOS token endpoint returned HTTP %d for deployment %s',
                $response->status(),
                $deployment->deploymentId,
            ));
        }

        $token = (string) $response->json('access_token', '');
        $ttl = (int) $response->json('expires_in', 0);

        if ($token === '' || $ttl <= 0) {
            throw new RuntimeException('EOS token response is missing access_token or expires_in');
        }

        $this->tokens[$deployment->deploymentId] = [
            'token' => $token,
            'expiresAt' => time() + $ttl,
        ];

        return $token;
    }

    /**
     * Blank the credentials out of a message before it becomes a log line.
     *
     * A failed response is normally a JSON body with no secrets in it, but a
     * 400 from Epic's gateway sometimes echoes the request URL and a 401
     * quotes the auth header — and neither is safe to write to disk in full.
     * Two substitutions per call is cheap; the alternative is a five-line
     * regex that misses the case it was written for.
     */
    private function redact(EosDeployment $deployment, string $body): string
    {
        if ($deployment->clientId !== '') {
            $body = str_replace($deployment->clientId, '[client_id]', $body);
        }
        if ($deployment->clientSecret !== '') {
            $body = str_replace($deployment->clientSecret, '[client_secret]', $body);
        }

        return $body === '' ? $deployment->deploymentId : $body;
    }
}
