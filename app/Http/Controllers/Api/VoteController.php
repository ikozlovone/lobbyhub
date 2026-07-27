<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Vote;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoteController extends Controller
{
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
                'user_id' => $request->user()?->id,
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
