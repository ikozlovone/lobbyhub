<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Server;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    /** Enough to fill the rail beside a listing, and no more. */
    private const RECENT_LIMIT = 12;

    /**
     * The votes cast on one game's servers, newest first.
     *
     * The only public trace a vote leaves, and deliberately a thin one: the
     * nickname a voter chose to publish so a server owner can reward them, and
     * nothing that would tie two votes to the same person. Votes cast without a
     * nickname are anonymous here as well.
     */
    public function recent(Game $game): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $votes = Vote::query()
            ->whereHas('server', fn (Builder $query) => $query->active()->where('game_id', $game->id))
            ->with('server:id,slug,name')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        return response()->json([
            'data' => $votes->map(fn (Vote $vote) => [
                'nickname' => $vote->nickname,
                'at' => $vote->created_at?->toIso8601String(),
                'server' => [
                    'slug' => $vote->server->slug,
                    'name' => $vote->server->name,
                ],
            ])->all(),
        ]);
    }

    /**
     * Cast a vote.
     *
     * One vote per address per server per day. The rule is enforced by a unique
     * index rather than a lookup: two requests arriving together would both pass
     * a "have they voted?" check and both insert.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $validated = $request->validate([
            // Optional, but it is how an owner knows who to reward in game.
            'nickname' => ['sometimes', 'nullable', 'string', 'min:2', 'max:64'],
        ]);

        $today = now()->toDateString();

        try {
            $vote = Vote::create([
                'server_id' => $server->id,
                'ip_hash' => Vote::hashIp((string) $request->ip()),
                'nickname' => $validated['nickname'] ?? null,
                // Named guard: voting is open to everyone, so no middleware has
                // authenticated this request, and the default guard is sessions
                // — which knows nothing about the bearer token a signed-in
                // visitor sends. Left as `user()` this column was always null.
                'user_id' => $request->user('sanctum')?->id,
                'vote_day' => $today,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'You have already voted for this server today.',
                'next_vote_at' => now()->addDay()->startOfDay()->toIso8601String(),
            ], 429);
        }

        return response()->json([
            'data' => [
                'voted' => true,
                'nickname' => $vote->nickname,
                'next_vote_at' => now()->addDay()->startOfDay()->toIso8601String(),
                // Counters are refreshed on a schedule; report the fresh number
                // so the page does not appear to have swallowed the vote.
                'votes_total' => $server->votes()->count(),
                'votes_today' => $server->votes()->where('vote_day', $today)->count(),
            ],
        ], 201);
    }

    /**
     * Whether an address may vote right now — lets the page show the button in
     * the right state instead of finding out by failing.
     */
    public function status(Request $request, Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $voted = $server->votes()
            ->where('ip_hash', Vote::hashIp((string) $request->ip()))
            ->where('vote_day', now()->toDateString())
            ->exists();

        return response()->json([
            'data' => [
                'can_vote' => ! $voted,
                'next_vote_at' => $voted ? now()->addDay()->startOfDay()->toIso8601String() : null,
                'votes_total' => $server->votes()->count(),
            ],
        ]);
    }

    /**
     * The reward hook server owners poll: "did this player vote, and have I paid
     * them yet?" Claiming marks the vote so a reward cannot be collected twice.
     *
     * Reads are open; claiming is not — it needs the server's claim token.
     */
    public function claim(Request $request, Server $server): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'min:2', 'max:64'],
            'token' => ['required', 'string'],
        ]);

        abort_unless(
            $server->claim_token !== null && hash_equals($server->claim_token, $validated['token']),
            403,
            'Invalid server token.',
        );

        $pending = $server->votes()
            ->unrewarded()
            ->where('nickname', $validated['nickname'])
            ->orderBy('id')
            ->get();

        $server->votes()->whereIn('id', $pending->pluck('id'))->update(['rewarded_at' => now()]);

        return response()->json([
            'data' => [
                'nickname' => $validated['nickname'],
                'rewards' => $pending->count(),
                'voted_at' => $pending->last()?->created_at?->toIso8601String(),
            ],
        ]);
    }
}
