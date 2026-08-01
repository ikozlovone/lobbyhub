<x-admin.layout title="Games" active="games">
    <h2>Catalog</h2>
    <div class="cards">
        <div class="card">
            <div class="v">{{ number_format($totals['games']) }}</div>
            <div class="k">games</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($totals['active']) }}</div>
            <div class="k">listed</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($totals['hidden']) }}</div>
            <div class="k">hidden</div>
            {{-- Not on the site and not accepting submissions, but still
                 monitored — a game parked rather than removed. --}}
            <div class="n">no landing page</div>
        </div>
    </div>

    <h2>All games</h2>
    <div class="toolbar">
        <form class="filters" method="get" style="margin: 0">
            <input type="search" name="q" value="{{ $search }}" placeholder="Name or slug" aria-label="Search">
            <select name="protocol" aria-label="Protocol">
                <option value="">Any protocol</option>
                @foreach(\App\Enums\QueryProtocol::cases() as $case)
                    <option value="{{ $case->value }}" @selected($protocol === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <select name="state" aria-label="Listed">
                <option value="">Listed and hidden</option>
                <option value="active" @selected($state === 'active')>Listed only</option>
                <option value="hidden" @selected($state === 'hidden')>Hidden only</option>
            </select>
            <button type="submit">Filter</button>
            @if($search !== '' || $protocol !== '' || $state !== '')
                <a href="{{ route('admin.games') }}" class="pill" style="align-self: center; text-decoration: none">Clear</a>
            @endif
        </form>
        <a class="button" href="{{ route('admin.games.create') }}">New game</a>
    </div>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th class="num">Order</th>
                    <th>Game</th>
                    <th>Protocol</th>
                    <th>Ports</th>
                    <th class="num">Steam</th>
                    <th class="num">Modes</th>
                    <th class="num">Versions</th>
                    <th class="num">Servers</th>
                    <th>Listed</th>
                </tr>
            </thead>
            <tbody>
                @forelse($games as $game)
                    <tr>
                        <td class="num subtle">{{ $game->sort_order }}</td>
                        <td class="wide">
                            <a href="{{ route('admin.games.edit', $game) }}">{{ $game->name }}</a>
                            <div class="subtle">{{ $game->slug }}</div>
                        </td>
                        <td class="muted">{{ $game->query_protocol->label() }}</td>
                        {{-- Query port shown only when it differs: for most games
                             it is the game port, and repeating it hides the few
                             where getting it wrong is the whole problem. --}}
                        <td class="muted">
                            {{ $game->default_port }}@if($game->default_query_port) <span class="subtle">/ {{ $game->default_query_port }}</span>@endif
                        </td>
                        <td class="num muted">{{ $game->steam_appid ?? '—' }}</td>
                        <td class="num">{{ $game->modes_count }}</td>
                        <td class="num">{{ $game->has_versions ? $game->versions_count : '—' }}</td>
                        <td class="num">{{ number_format($game->servers_count) }}</td>
                        <td>
                            <span class="pill {{ $game->is_active ? 'online' : 'offline' }}">
                                {{ $game->is_active ? 'listed' : 'hidden' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty">No game matches that.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.layout>
