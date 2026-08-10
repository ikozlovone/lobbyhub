<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QueryProtocol;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameMode;
use App\Models\GameVersion;
use App\Services\Admin\GameArtwork;
use App\Services\Catalog\FrontendCache;
use App\Services\Catalog\ListingCache;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The catalog, editable.
 *
 * Until now a game could only be added by editing GameSeeder and re-running it,
 * which is fine for the initial list and useless for the day somebody notices a
 * wrong query port at eight in the evening. Everything the seeder writes is
 * writable here, including the modes and versions that become facet pages.
 *
 * The seeder stays the source of truth for a fresh install and is still
 * idempotent, so anything edited here that also exists there will be overwritten
 * the next time it runs. Rows added here are left alone by it.
 */
class GameController extends Controller
{
    public function __construct(
        private readonly FrontendCache $frontend,
        private readonly ListingCache $listings,
    ) {}

    /**
     * Every game at once — the whole catalog is a few dozen rows, so paging it
     * would only hide the thing this page is for: seeing the order they appear
     * in on the site.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $protocol = (string) $request->query('protocol', '');
        $state = (string) $request->query('state', '');

        $games = Game::query()
            ->withCount(['modes', 'versions', 'servers'])
            ->when($search !== '', fn ($query) => $query->where(function ($where) use ($search) {
                $where->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            }))
            ->when($protocol !== '', fn ($query) => $query->where('query_protocol', $protocol))
            ->when($state === 'active', fn ($query) => $query->where('is_active', true))
            ->when($state === 'hidden', fn ($query) => $query->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.games.index', [
            'games' => $games,
            'search' => $search,
            'protocol' => $protocol,
            'state' => $state,
            'totals' => [
                'games' => Game::count(),
                'active' => Game::where('is_active', true)->count(),
                'hidden' => Game::where('is_active', false)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        // Defaults that match how the catalog is actually filled: an A2S game,
        // landing at the end of the list, visible once it has something to show.
        $game = new Game([
            'query_protocol' => QueryProtocol::Source,
            'is_active' => true,
            'has_versions' => false,
            'sort_order' => (int) Game::max('sort_order') + 10,
        ]);

        return view('admin.games.form', ['game' => $game, 'artwork' => GameArtwork::ROLES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $game = new Game;
        $game->fill($this->validated($request, $game))->save();

        $this->applyArtwork($request, $game);

        $this->published($game);

        // Straight to the edit screen: a game without modes has no facets, and
        // that is the next thing to fill in, not something to discover later.
        return redirect()
            ->route('admin.games.edit', $game)
            ->with('status', "Added {$game->name}. Modes and versions are below.");
    }

    public function edit(Game $game): View
    {
        $game->load([
            'modes' => fn ($query) => $query->withCount('servers')->orderBy('sort_order')->orderBy('name'),
            'versions' => fn ($query) => $query->withCount('servers')->orderBy('sort_order')->orderBy('name'),
        ]);

        return view('admin.games.form', [
            'game' => $game,
            'artwork' => GameArtwork::ROLES,
            // Counted rather than read off the game's own counter: that one is
            // refreshed on a schedule, and this decides whether a delete button
            // is offered at all. Trashed servers count — they come back.
            'servers' => $game->servers()->withTrashed()->count(),
        ]);
    }

    /**
     * One form, one save: the game's own columns and its facet rows go in
     * together, so reordering modes and fixing a port is a single trip.
     *
     * In one transaction, because a row can still be refused halfway down the
     * form — two modes claiming one slug, say. Without it the rows above the
     * refused one would be saved and the ones below would not, and the screen
     * that came back would be showing a state nobody asked for.
     */
    public function update(Request $request, Game $game): RedirectResponse
    {
        DB::transaction(function () use ($request, $game) {
            $game->fill($this->validated($request, $game))->save();

            $this->syncFacets($request, $game);

            $this->applyArtwork($request, $game);
        });

        // A renamed game used to need its old slug expiring too, or the
        // frontend kept serving the old page from that tag. Nothing caches a
        // game's page any more: the old address 404s on the next request by
        // itself, and the new one renders from the row we just saved.
        $this->published($game);

        return redirect()
            ->route('admin.games.edit', $game)
            ->with('status', 'Saved.');
    }

    /**
     * Deleting a game takes its servers with it — the foreign key cascades, and
     * so do their stats, votes and history. So this is only offered while there
     * is nothing to lose; a listed game is hidden instead, which is what
     * "remove it from the site" almost always means.
     */
    public function destroy(Game $game): RedirectResponse
    {
        if ($game->servers()->withTrashed()->exists()) {
            return back()->withErrors([
                'delete' => 'This game still has servers. Uncheck "listed" to hide it instead.',
            ]);
        }

        $name = $game->name;
        $game->delete();

        $this->published($game);

        return redirect()
            ->route('admin.games')
            ->with('status', "Deleted {$name}.");
    }

    /**
     * Push an edit through both caches that stand between it and a visitor.
     *
     * Order matters. The API answers from a short-lived cache of its own, and
     * telling the frontend to revalidate first would have it refetch the very
     * payload we just replaced — storing the old catalog for another window,
     * this time with nothing left to invalidate it.
     *
     * One tag, because the frontend now keeps exactly one thing: the game
     * catalog behind its navigation rail. Its pages read the listing, the
     * facets and the counters when the request arrives, so an edit is visible
     * on the next page view whether anything is invalidated or not.
     */
    private function published(Game $game): void
    {
        Cache::forget('api:games');

        // An edit can add or rename a mode or a version, and those are facets.
        // The game's own fields are read per request and need no telling.
        $this->listings->forget([$game->slug]);

        $this->frontend->invalidate('games');
    }

    /**
     * The game's own columns.
     *
     * Counters are absent on purpose: servers_count and the rest are written by
     * the monitor, and a number typed over them here would survive exactly until
     * the next refresh.
     */
    private function validated(Request $request, Game $game): array
    {
        $unique = Rule::unique('games', 'slug');

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $game->exists ? $unique->ignore($game->id) : $unique],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:32'],
            'aliases' => ['nullable', 'string'],
            'links' => ['array'],
            'links.*.name' => ['nullable', 'string', 'max:64'],
            // Only the two schemes a browser will follow from a link on a page.
            'links.*.url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'steam_appid' => ['nullable', 'integer', 'min:1'],
            'query_protocol' => ['required', Rule::enum(QueryProtocol::class)],
            'default_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'default_query_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'icon_path' => ['nullable', 'string', 'max:255'],
            'cover_path' => ['nullable', 'string', 'max:255'],
            'hero_path' => ['nullable', 'string', 'max:255'],
            /*
             * Uploads, one per role. `image` checks that the file really is one
             * rather than trusting what the browser called it, and the extension
             * list is what a browser will display — svg is deliberately absent,
             * being a document that can carry script.
             *
             * 4MB: a 460x215 JPEG is about 40KB, so this is generous enough for
             * a banner nobody has optimised and small enough that the real limit
             * stays PHP's, which says so with a 413.
             */
            'artwork' => ['array'],
            'artwork.*' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:4096'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ], [
            'slug.regex' => 'The slug is part of a URL: lowercase letters, digits and single dashes.',
            'accent_color.regex' => 'The accent colour has to be a six-digit hex value, like #4C9A2A.',
        ]);

        // A textarea is the honest editor for a list this short. Empty means no
        // synonyms at all, and the column is nullable rather than an empty array
        // so it matches what the seeder writes.
        $validated['aliases'] = $this->lines($request->input('aliases')) ?: null;

        $validated['links'] = $this->links($request) ?: null;

        // Unchecked boxes are not submitted, so they have to be read from the
        // request rather than from what validation happened to see.
        $validated['is_active'] = $request->boolean('is_active');
        $validated['has_versions'] = $request->boolean('has_versions');

        // Files are not columns. They are handled after the save, by
        // applyArtwork, which needs the slug the save has just settled.
        unset($validated['artwork']);

        return $validated;
    }

    /**
     * The three pictures, after the game has a slug to be named after.
     *
     * Runs post-save on purpose: a new game's slug is only certain once it has
     * been through validation and written, and the filenames are built from it.
     * Nothing here is fatal — a save that stored the columns and failed to move
     * a file should still be a save.
     */
    private function applyArtwork(Request $request, Game $game): void
    {
        $artwork = app(GameArtwork::class);
        $changed = [];

        foreach (array_keys(GameArtwork::ROLES) as $role) {
            $file = $request->file("artwork.{$role}");

            if ($file !== null) {
                $changed[$role] = $artwork->store($game, $role, $file);

                continue;
            }

            // A checkbox rather than clearing the text field: the path is also
            // editable by hand, and "empty the box" cannot mean both "no
            // picture" and "I did not touch this".
            if ($request->boolean("remove.{$role}")) {
                $artwork->forget($game, $role);
                $changed[$role] = null;
            }
        }

        if ($changed !== []) {
            $game->forceFill($changed)->save();
        }
    }

    /**
     * Modes and versions, submitted as repeated rows alongside the game.
     *
     * Blank rows are how new ones are added — the form always renders a few
     * spare — so a row with neither slug nor name is not an error, it is an
     * untouched template.
     */
    private function syncFacets(Request $request, Game $game): void
    {
        $request->validate([
            'modes' => ['array'],
            'modes.*.id' => ['nullable', 'integer'],
            'modes.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'modes.*.name' => ['nullable', 'string', 'max:255'],
            'modes.*.description' => ['nullable', 'string'],
            'modes.*.meta_title' => ['nullable', 'string', 'max:255'],
            'modes.*.meta_description' => ['nullable', 'string', 'max:320'],
            'modes.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'versions' => ['array'],
            'versions.*.id' => ['nullable', 'integer'],
            'versions.*.slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'versions.*.name' => ['nullable', 'string', 'max:64'],
            'versions.*.released_at' => ['nullable', 'date'],
            'versions.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'modes.*.slug.regex' => 'A mode slug is part of a URL: lowercase letters, digits and single dashes.',
            'versions.*.slug.regex' => 'A version slug is part of a URL: lowercase letters, digits and single dashes.',
        ]);

        $this->applyRows(
            $request->input('modes', []),
            'modes',
            $game->modes()->get()->keyBy('id'),
            fn (array $row) => [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'meta_title' => $row['meta_title'] ?? null,
                'meta_description' => $row['meta_description'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            fn (array $attributes) => $game->modes()->create($attributes),
        );

        $this->applyRows(
            $request->input('versions', []),
            'versions',
            $game->versions()->get()->keyBy('id'),
            fn (array $row) => [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'released_at' => $row['released_at'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            fn (array $attributes) => $game->versions()->create($attributes),
        );
    }

    /**
     * Walk one set of submitted rows: delete what is ticked, update what has an
     * id, create what has a slug and a name, ignore the rest.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, GameMode|GameVersion>  $existing
     * @param  callable(array<string, mixed>): array<string, mixed>  $attributes
     * @param  callable(array<string, mixed>): Model  $create
     */
    private function applyRows(
        array $rows,
        string $field,
        Collection $existing,
        callable $attributes,
        callable $create,
    ): void {
        $seen = [];

        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $slug = trim((string) ($row['slug'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            // Somebody else's row, or one already deleted in another tab.
            $model = $id ? $existing->get($id) : null;

            if ($id && ! $model) {
                continue;
            }

            if ($model && filled($row['delete'] ?? null)) {
                $model->delete();

                continue;
            }

            if ($slug === '' && $name === '') {
                // An existing row cannot be emptied into nothing — that reads as
                // an accident, and deleting has its own checkbox.
                if ($model) {
                    throw ValidationException::withMessages([
                        "{$field}.{$index}.name" => 'Give it a name, or tick delete.',
                    ]);
                }

                continue;
            }

            if ($slug === '' || $name === '') {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.".($slug === '' ? 'slug' : 'name') => 'Both the slug and the name are needed.',
                ]);
            }

            // Slugs are the facet URLs, and the table only enforces uniqueness
            // per game — which is exactly what two rows in one form can break.
            if (isset($seen[$slug])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.slug" => "\"{$slug}\" is already used by another row.",
                ]);
            }

            $seen[$slug] = true;

            $values = $attributes(['slug' => $slug, 'name' => $name] + $row);

            $model ? $model->update($values) : $create($values);
        }
    }

    /**
     * The rows of the links table, in the order they were typed.
     *
     * Blank rows are the way new ones are added, so an empty pair is skipped
     * rather than refused. A row with one half filled is a mistake worth
     * stopping on: a label with no address is a link that goes nowhere, and an
     * address with no label has nothing to render.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function links(Request $request): array
    {
        $links = [];

        foreach ((array) $request->input('links', []) as $index => $row) {
            $name = trim((string) (is_array($row) ? $row['name'] ?? '' : ''));
            $url = trim((string) (is_array($row) ? $row['url'] ?? '' : ''));

            if ($name === '' && $url === '') {
                continue;
            }

            if ($name === '' || $url === '') {
                throw ValidationException::withMessages([
                    "links.{$index}.".($name === '' ? 'name' : 'url') => 'A link needs both a label and an address.',
                ]);
            }

            $links[] = ['name' => $name, 'url' => $url];
        }

        return $links;
    }

    /**
     * A textarea into a list: one entry per line, blanks and stray spaces gone.
     *
     * @return array<int, string>
     */
    private function lines(?string $text): array
    {
        return collect(preg_split('/\R/', (string) $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }
}
