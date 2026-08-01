@php
    /*
        One screen for a game and everything hanging off it.

        The facet rows are part of the same form as the game's own columns, so
        renaming a mode and fixing a port is one save, not three. Spare blank
        rows at the end of each list are how new ones are added — no JavaScript
        in an admin that has no build step.
    */
    $blankRows = 3;
    $aliases = old('aliases', implode("\n", $game->aliases ?? []));
@endphp

<x-admin.layout :title="$game->exists ? $game->name : 'New game'" active="games">
    <h2>
        <a href="{{ route('admin.games') }}" style="text-decoration: none">Games</a>
        /
        {{ $game->exists ? $game->name : 'new game' }}
    </h2>

    <form method="post" action="{{ $game->exists ? route('admin.games.update', $game) : route('admin.games.store') }}">
        @csrf
        @if($game->exists)
            @method('put')
        @endif

        <div class="panel" style="padding: 16px">
            <div class="fields">
                <label class="field">
                    <span class="l">Name</span>
                    <input type="text" name="name" value="{{ old('name', $game->name) }}" required autofocus>
                    @error('name')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Slug</span>
                    <input type="text" name="slug" value="{{ old('slug', $game->slug) }}" required>
                    <div class="hint">/games/{{ old('slug', $game->slug) ?: 'slug' }} — changing it breaks existing links.</div>
                    @error('slug')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Short name</span>
                    <input type="text" name="short_name" value="{{ old('short_name', $game->short_name) }}" maxlength="32">
                    <div class="hint">Used where the full name will not fit: "MC", "GTA RP".</div>
                    @error('short_name')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Aliases</span>
                    <textarea name="aliases" rows="4" placeholder="mc&#10;майнкрафт">{{ $aliases }}</textarea>
                    <div class="hint">One per line. What search has to match besides the name.</div>
                    @error('aliases')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
            </div>
        </div>

        <h2>Monitoring</h2>
        <div class="panel" style="padding: 16px">
            <div class="fields">
                <label class="field">
                    <span class="l">Query protocol</span>
                    <select name="query_protocol" required>
                        @foreach(\App\Enums\QueryProtocol::cases() as $case)
                            <option value="{{ $case->value }}"
                                @selected(old('query_protocol', $game->query_protocol?->value) === $case->value)>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">Only these three are implemented — a game set to one it does not speak will never answer.</div>
                    @error('query_protocol')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Steam app id</span>
                    <input type="number" name="steam_appid" value="{{ old('steam_appid', $game->steam_appid) }}" min="1">
                    <div class="hint">Drives cover art and Steam server discovery. Empty for games that are not on Steam.</div>
                    @error('steam_appid')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Default game port</span>
                    <input type="number" name="default_port" value="{{ old('default_port', $game->default_port) }}" min="1" max="65535" required>
                    @error('default_port')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Default query port</span>
                    <input type="number" name="default_query_port" value="{{ old('default_query_port', $game->default_query_port) }}" min="1" max="65535">
                    {{-- Both of these are hints for the submission form. A server's
                         own query port wins, and falls back to its game port, so a
                         wrong number here cannot misdirect the monitor. --}}
                    <div class="hint">Empty means the game port. A hint for the submission form, not what we query.</div>
                    @error('default_query_port')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
            </div>
        </div>

        <h2>Presentation</h2>
        <div class="panel" style="padding: 16px">
            <div class="fields">
                <label class="field">
                    <span class="l">Accent colour</span>
                    <input type="text" name="accent_color" value="{{ old('accent_color', $game->accent_color) }}" placeholder="#4C9A2A">
                    @if($colour = old('accent_color', $game->accent_color))
                        <div class="hint">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: {{ $colour }}"></span>
                            currently {{ $colour }}
                        </div>
                    @endif
                    @error('accent_color')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <div class="field">
                    <span class="l">Listing</span>
                    <div class="check" style="margin-bottom: 8px">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $game->is_active ?? true))>
                        <label for="is_active">Listed on the site</label>
                    </div>
                    <div class="check">
                        <input type="hidden" name="has_versions" value="0">
                        <input type="checkbox" id="has_versions" name="has_versions" value="1" @checked(old('has_versions', $game->has_versions ?? false))>
                        <label for="has_versions">Has version pages</label>
                    </div>
                </div>
                <label class="field">
                    <span class="l">Cover path</span>
                    <input type="text" name="cover_path" value="{{ old('cover_path', $game->cover_path) }}">
                    <div class="hint">Filled by <code>php artisan games:artwork</code> from the Steam app id.</div>
                    @error('cover_path')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Icon path</span>
                    <input type="text" name="icon_path" value="{{ old('icon_path', $game->icon_path) }}">
                    <div class="hint">Relative to <code>public/</code>, like <code>images/games/rust.jpg</code>.</div>
                    @error('icon_path')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Sort order</span>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $game->sort_order ?? 0) }}" min="0" max="65535" required>
                    <div class="hint">Low first. The catalog leaves gaps of ten so a game can be slotted in.</div>
                    @error('sort_order')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
            </div>
            <div class="fields one" style="margin-top: 14px">
                <label class="field">
                    <span class="l">Description</span>
                    <textarea name="description" rows="4">{{ old('description', $game->description) }}</textarea>
                    <div class="hint">Shown on the game's own page only.</div>
                    @error('description')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
            </div>
        </div>

        <h2>Links</h2>
        <p class="subtle" style="margin: -4px 0 10px">
            Where this game lives elsewhere — its site, its docs, where servers are set up. Shown
            at the top of the game's page; most games have none. Blank rows add more.
        </p>
        <div class="rows">
            @php
                // Filled rows first, then spares. old() wins so a refused save
                // does not throw away what was typed.
                $links = old('links', array_map(
                    fn ($link) => ['name' => $link['name'] ?? '', 'url' => $link['url'] ?? ''],
                    $game->links ?? [],
                ));
                $links = array_values($links) + array_fill(count($links), $blankRows, ['name' => '', 'url' => '']);
            @endphp
            @foreach($links as $i => $link)
                <div class="row-card">
                    <div class="row-grid" style="grid-template-columns: 1fr 2fr">
                        <label class="field">
                            <span class="l">Label</span>
                            <input type="text" name="links[{{ $i }}][name]" value="{{ $link['name'] ?? '' }}"
                                   maxlength="64" placeholder="FiveM Docs">
                            @error("links.$i.name")<div class="bad-field">{{ $message }}</div>@enderror
                        </label>
                        <label class="field">
                            <span class="l">Address</span>
                            <input type="text" name="links[{{ $i }}][url]" value="{{ $link['url'] ?? '' }}"
                                   maxlength="255" placeholder="https://docs.fivem.net/">
                            @error("links.$i.url")<div class="bad-field">{{ $message }}</div>@enderror
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
        {{-- Emptying both fields of a row is how a link is removed: there is no
             pivot, no URL and nothing else pointing at it, so a delete checkbox
             would be a second way to do the same thing. --}}
        <p class="hint">Clear both fields to remove a link.</p>

        <h2>Search engines</h2>
        <div class="panel" style="padding: 16px">
            <div class="fields one">
                <label class="field">
                    <span class="l">Meta title</span>
                    <input type="text" name="meta_title" value="{{ old('meta_title', $game->meta_title) }}" maxlength="255">
                    @error('meta_title')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
                <label class="field">
                    <span class="l">Meta description</span>
                    <textarea name="meta_description" rows="2" maxlength="320">{{ old('meta_description', $game->meta_description) }}</textarea>
                    <div class="hint">Up to 320 characters; search results cut it off well before that.</div>
                    @error('meta_description')<div class="bad-field">{{ $message }}</div>@enderror
                </label>
            </div>
        </div>

        @if($game->exists)
            <h2>Modes</h2>
            <p class="subtle" style="margin: -4px 0 10px">
                Each one is a facet page under the game. Blank rows at the end add new modes.
            </p>
            <div class="rows">
                @foreach($game->modes->values() as $i => $mode)
                    @include('admin.games.mode-row', ['i' => $i, 'mode' => $mode])
                @endforeach
                @for($i = $game->modes->count(); $i < $game->modes->count() + $blankRows; $i++)
                    @include('admin.games.mode-row', ['i' => $i, 'mode' => null])
                @endfor
            </div>

            <h2>Versions</h2>
            <p class="subtle" style="margin: -4px 0 10px">
                Only reachable on the site while "has version pages" is on.
            </p>
            <div class="rows">
                @foreach($game->versions->values() as $i => $version)
                    @include('admin.games.version-row', ['i' => $i, 'version' => $version])
                @endforeach
                @for($i = $game->versions->count(); $i < $game->versions->count() + $blankRows; $i++)
                    @include('admin.games.version-row', ['i' => $i, 'version' => null])
                @endfor
            </div>
        @endif

        <div class="actions">
            <button class="primary" type="submit">{{ $game->exists ? 'Save game' : 'Add game' }}</button>
            <a class="button" href="{{ route('admin.games') }}">Cancel</a>
        </div>
    </form>

    @if($game->exists)
        <h2>Counters</h2>
        <div class="panel">
            <table>
                <tbody>
                    <tr>
                        <td class="subtle">Servers</td>
                        <td class="num">{{ number_format($game->servers_count) }}</td>
                        <td class="subtle">Online</td>
                        <td class="num">{{ number_format($game->online_servers_count) }}</td>
                        <td class="subtle">Players</td>
                        <td class="num">{{ number_format($game->players_online) }}</td>
                        <td class="subtle">Refreshed</td>
                        <td class="muted">{{ $game->stats_synced_at?->diffForHumans() ?? 'never' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        {{-- Written by the monitor on every refresh, so there is nothing to edit
             here: a number typed over them would last until the next pass. --}}
        <p class="hint">Written by <code>php artisan counters:refresh</code>, not editable.</p>

        <h2>Danger</h2>
        <div class="panel" style="padding: 16px">
            @if($servers > 0)
                <p class="muted" style="margin: 0">
                    {{ number_format($servers) }} servers belong to this game, and deleting it would
                    delete them and their history with it. Uncheck "listed" to take the game off the site instead.
                </p>
            @else
                <form method="post" action="{{ route('admin.games.destroy', $game) }}"
                      onsubmit="return confirm('Delete {{ $game->name }}?')">
                    @csrf
                    @method('delete')
                    <button class="danger" type="submit">Delete this game</button>
                    <span class="hint" style="margin-left: 10px">No servers listed, so nothing else goes with it.</span>
                </form>
            @endif
        </div>
    @endif
</x-admin.layout>
