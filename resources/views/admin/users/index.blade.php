<x-admin.layout title="Users" active="users">
    <h2>Accounts</h2>
    <div class="cards">
        <div class="card">
            <div class="v">{{ number_format($totals['users']) }}</div>
            <div class="k">accounts</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($totals['with_provider']) }}</div>
            <div class="k">signed in with a provider</div>
        </div>
        <div class="card">
            <div class="v">{{ number_format($totals['code_only']) }}</div>
            <div class="k">emailed code only</div>
            {{-- No social row at all: the account exists because somebody typed
                 six digits from their inbox. --}}
            <div class="n">no linked provider</div>
        </div>
    </div>

    <h2>All accounts</h2>
    <form class="filters" method="get">
        <input type="search" name="q" value="{{ $search }}" placeholder="Address or name" aria-label="Search">
        <select name="provider" aria-label="Provider">
            <option value="">Any way in</option>
            @foreach(['steam' => 'Steam', 'discord' => 'Discord', 'google' => 'Google', 'email' => 'Email code only'] as $key => $label)
                <option value="{{ $key }}" @selected($provider === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit">Filter</button>
        @if($search !== '' || $provider !== '')
            <a href="{{ route('admin.users') }}" class="pill" style="align-self: center; text-decoration: none">Clear</a>
        @endif
    </form>

    <div class="panel">
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Signed in with</th>
                    <th>Last seen</th>
                    <th>Joined</th>
                    <th class="num">Submitted</th>
                    <th class="num">Claimed</th>
                    <th class="num">Votes</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="wide">
                            <a href="{{ route('admin.users.show', $user) }}">{{ $user->name ?: '—' }}</a>
                            <div class="subtle">{{ $user->email ?: 'no address' }}</div>
                        </td>
                        <td>
                            @forelse($user->socialAccounts as $account)
                                <span class="pill">{{ $account->provider }}</span>
                            @empty
                                <span class="pill">email code</span>
                            @endforelse
                        </td>
                        <td class="muted">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</td>
                        <td class="muted">{{ $user->created_at?->toDateString() }}</td>
                        <td class="num">{{ $user->submissions_count }}</td>
                        <td class="num">{{ $user->servers_count }}</td>
                        <td class="num">{{ $user->votes_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">Nobody matches that.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
        <div class="pager">{{ $users->links('admin.pagination') }}</div>
    @endif
</x-admin.layout>
