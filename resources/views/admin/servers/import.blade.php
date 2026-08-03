@php
    /** @var \App\Services\Catalog\BulkImportReport|null $report */
    $selected = old('game', isset($game) ? $game->slug : ($games->first()->slug ?? ''));
@endphp

<x-admin.layout title="Import servers" active="servers">
    <div class="toolbar">
        <h2 style="margin:0">Import servers</h2>
    </div>

    @if($report)
        <div class="notice">{{ $report->summary() }}</div>
    @endif

    @if($games->isEmpty())
        <div class="panel"><div class="empty">
            No game can be imported into: every active game uses a protocol we have no monitor for.
        </div></div>
    @else
    <form method="post" action="{{ route('admin.servers.import.store') }}">
        @csrf

        <div class="fields one">
            <div class="field">
                <span class="l">Game</span>
                <select name="game" required>
                    @foreach($games as $option)
                        <option value="{{ $option->slug }}" @selected($selected === $option->slug)>
                            {{ $option->name }} — port {{ $option->default_port }}@if($option->default_query_port), query {{ $option->default_query_port }}@endif
                        </option>
                    @endforeach
                </select>
                @error('game')<div class="bad-field">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <span class="l">Servers — one per line</span>
                <textarea name="servers" rows="16" spellcheck="false"
                          placeholder="1.2.3.4:28015&#10;1.2.3.4:28015|28016&#10;play.example.com:25565">{{ old('servers') }}</textarea>
                <div class="hint">
                    <code>host:port</code> for the address players connect to. Add the query port
                    after a <code>|</code>, a comma, a tab or a space when it differs from the game
                    port — <code>1.2.3.4:28015|28016</code>. Leave it off and the game's default is
                    used. The port may be left off too. Blank lines and lines starting with
                    <code>#</code> are ignored. There is no limit on how many lines you paste.
                </div>
                @error('servers')<div class="bad-field">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="primary">Import</button>
            <span class="subtle">
                Nothing is queried now. These go to the front of the monitoring queue and appear in
                the listings — and in “recently added” — once our own check reaches them.
            </span>
        </div>
    </form>
    @endif

    @if($report)
        @if($report->added || $report->restored)
            <h2>Added</h2>
            <div class="panel">
                <table>
                    <thead><tr><th class="num">Line</th><th>Address</th><th></th><th></th></tr></thead>
                    <tbody>
                    @foreach($report->entries as $entry)
                        @continue($entry['kind'] === 'skipped')
                        <tr>
                            <td class="num subtle">{{ $entry['line'] }}</td>
                            <td>{{ $entry['address'] }}</td>
                            <td>
                                <span class="pill unknown">
                                    {{ $entry['kind'] === 'restored' ? 'restored' : 'awaiting first check' }}
                                </span>
                            </td>
                            <td class="subtle">{{ $entry['slug'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($report->rejected !== [])
            <h2>Could not be read</h2>
            <div class="panel">
                <table>
                    <thead><tr><th class="num">Line</th><th>Input</th><th class="wide">Reason</th></tr></thead>
                    <tbody>
                    @foreach($report->rejected as $bad)
                        <tr>
                            <td class="num subtle">{{ $bad['line'] }}</td>
                            <td>{{ $bad['input'] }}</td>
                            <td class="wide muted">{{ $bad['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($report->skipped > 0)
            <h2>Already listed</h2>
            <div class="panel">
                <table>
                    <thead><tr><th class="num">Line</th><th>Address</th><th>Existing server</th></tr></thead>
                    <tbody>
                    @foreach($report->skippedEntries() as $entry)
                        <tr>
                            <td class="num subtle">{{ $entry['line'] }}</td>
                            <td>{{ $entry['address'] }}</td>
                            <td class="subtle">{{ $entry['slug'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-admin.layout>
