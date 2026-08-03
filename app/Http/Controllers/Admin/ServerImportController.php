<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Catalog\BulkServerImport;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Paste a list of addresses into a game.
 *
 * Nothing here is throttled and nothing caps the number of lines, which is what
 * was asked for and is only defensible because /admin is meant to be closed off
 * at the edge. It is not, yet — see the note on the route group. An open
 * unbounded insert is a materially worse thing to leave unauthenticated than
 * the read-only screens this sits beside.
 */
class ServerImportController extends Controller
{
    public function create(ServerQueryManager $manager): View
    {
        return view('admin.servers.import', [
            'games' => $this->games($manager),
            'report' => null,
        ]);
    }

    public function store(Request $request, BulkServerImport $import, ServerQueryManager $manager): RedirectResponse|View
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'exists:games,slug'],
            // No max: the whole point of the screen is that a list can be as
            // long as it is. What bounds it is PHP's post_max_size, which is
            // infrastructure and says so with a 413 rather than a validation
            // error.
            'servers' => ['required', 'string'],
        ]);

        $game = Game::where('slug', $validated['game'])->firstOrFail();

        $report = $import->import($game, $validated['servers']);

        if ($report->total() === 0) {
            return back()
                ->withInput()
                ->withErrors(['servers' => 'Nothing to import — every line was blank or a comment.']);
        }

        // Rendered rather than redirected: the per-line outcome is the useful
        // part of an import and it does not survive a flash message. The form
        // comes back with the same game selected and an empty box, because the
        // next paste is a different list.
        return view('admin.servers.import', [
            'games' => $this->games($manager),
            'report' => $report,
            'game' => $game,
        ]);
    }

    /**
     * Only games we can actually monitor.
     *
     * Importing into a game whose protocol has no driver writes rows that the
     * dispatcher skips every cycle — they would sit unverified for ever, which
     * looks exactly like an import that silently failed.
     *
     * @return Collection<int, Game>
     */
    private function games(ServerQueryManager $manager)
    {
        return Game::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Game $game) => $manager->supports($game->query_protocol))
            ->values();
    }
}
