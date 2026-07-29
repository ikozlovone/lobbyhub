<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\Game;
use App\Services\Catalog\Exceptions\ServerAlreadyListed;
use App\Services\Catalog\ServerSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerSubmissionController extends Controller
{
    /**
     * Add a server to a game's listing.
     *
     * One field carries the whole form: the address a player would connect to.
     * The query port is asked for separately because a handful of hosts run it
     * apart from the game port, and getting it wrong is the single most common
     * reason a live server looks offline.
     *
     * Everything the listing shows — name, players, map, version — is read from
     * the server itself during verification. The submitter is never asked for
     * numbers we are going to measure anyway.
     */
    public function store(Request $request, Game $game, ServerSubmission $submission): JsonResponse
    {
        abort_unless($game->is_active, 404);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'query_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            $server = $submission->submit($game, $validated['address'], $validated['query_port'] ?? null);
        } catch (ServerAlreadyListed $listed) {
            return response()->json([
                'message' => 'This server is already in the catalog.',
                'data' => ['slug' => $listed->server->slug, 'name' => $listed->server->name],
            ], 409);
        }

        return (new ServerResource($server))
            // Not "it will appear after the first check" any more: the check
            // already happened — it is what let us save the row at all.
            ->additional(['message' => 'Verified and added to the listing.'])
            ->response()
            ->setStatusCode(201);
    }
}
