<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Catalog\ServerHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class ServerHistoryController extends Controller
{
    public function show(Request $request, Server $server, ServerHistory $history): JsonResponse
    {
        abort_unless($server->is_active, 404);

        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(ServerHistory::ranges())],
        ]);

        $range = $validated['range'] ?? '24h';

        // Chart data is the heaviest read in the API; even a minute of caching
        // collapses a burst of page views into one query.
        $payload = Cache::remember(
            "api:servers:{$server->id}:history:{$range}",
            600,
            fn () => $history->for($server, $range),
        );

        return response()->json(['data' => $payload]);
    }
}
