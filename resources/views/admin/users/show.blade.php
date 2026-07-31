@php
    // Where the public site lives, taken from the origin already configured for
    // CORS rather than from a second setting that could disagree with it.
    $site = rtrim((string) (config('cors.allowed_origins')[0] ?? 'https://lobbyhub.gg'), '/');
@endphp

<x-admin.layout :title="$user->name ?: 'Account'" active="users">
    <h2><a href="{{ route('admin.users') }}" class="subtle">Users</a> / {{ $user->name ?: 'Account' }}</h2>

    <div class="cards">
        <div class="card">
            <div class="v" style="font-size: 18px">{{ $user->email ?: 'no address' }}</div>
            <div class="k">account #{{ $user->id }}</div>
            <div class="n">
                joined {{ $user->created_at?->toDateString() }},
                last seen {{ $user->last_login_at?->diffForHumans() ?? 'never' }}
            </div>
        </div>
        <div class="card">
            <div class="v">{{ $user->submissions->count() }}</div>
            <div class="k">servers submitted</div>
        </div>
        <div class="card">
            <div class="v">{{ $user->servers->count() }}</div>
            <div class="k">servers claimed</div>
        </div>
        <div class="card">
            <div class="v">{{ $user->votes->count() }}</div>
            <div class="k">votes cast</div>
        </div>
    </div>

    <h2>Ways in</h2>
    <div class="panel">
        <table>
            <thead>
                <tr><th>Provider</th><th>Nickname there</th><th>Provider id</th><th>Linked</th><th>Last used</th></tr>
            </thead>
            <tbody>
                @forelse($user->socialAccounts as $account)
                    <tr>
                        <td><span class="pill">{{ $account->provider }}</span></td>
                        <td class="wide">{{ $account->nickname ?: '—' }}</td>
                        <td class="subtle">{{ $account->provider_id }}</td>
                        <td class="muted">{{ $account->created_at?->toDateString() }}</td>
                        <td class="muted">{{ $account->last_login_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    {{-- Signing in with a code leaves no provider row: the mailbox
                         is the identity, and it is already the account's address. --}}
                    <tr><td colspan="5" class="empty">No provider linked — signs in with an emailed code.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Servers submitted</h2>
    <div class="panel">
        <table>
            <thead>
                <tr><th>Server</th><th>Game</th><th>Address</th><th>Status</th><th>Added</th></tr>
            </thead>
            <tbody>
                @forelse($user->submissions as $server)
                    <tr>
                        <td class="wide"><a href="{{ $site }}/servers/{{ $server->slug }}">{{ $server->name }}</a></td>
                        <td class="subtle">{{ $server->game?->name }}</td>
                        <td class="subtle">{{ $server->host }}:{{ $server->port }}</td>
                        <td><span class="pill {{ $server->status->value }}">{{ $server->status->value }}</span></td>
                        <td class="muted">{{ $server->created_at?->toDateString() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">None. Servers added before this was recorded do not show here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Servers claimed</h2>
    <div class="panel">
        <table>
            <thead>
                <tr><th>Server</th><th>Game</th><th>Claimed</th></tr>
            </thead>
            <tbody>
                @forelse($user->servers as $server)
                    <tr>
                        <td class="wide"><a href="{{ $site }}/servers/{{ $server->slug }}">{{ $server->name }}</a></td>
                        <td class="subtle">{{ $server->game?->name }}</td>
                        <td class="muted">{{ $server->claimed_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>Votes</h2>
    <div class="panel">
        <table>
            <thead>
                <tr><th>Server</th><th>Nickname given</th><th>When</th></tr>
            </thead>
            <tbody>
                @forelse($user->votes as $vote)
                    <tr>
                        <td class="wide"><a href="{{ $site }}/servers/{{ $vote->server?->slug }}">{{ $vote->server?->name }}</a></td>
                        <td class="muted">{{ $vote->nickname ?: '—' }}</td>
                        <td class="muted">{{ $vote->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.layout>
