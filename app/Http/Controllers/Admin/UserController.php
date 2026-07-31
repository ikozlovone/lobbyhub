<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Everyone who has ever signed in, newest first.
     *
     * Search covers the address and the display name, and the provider filter
     * answers the question this page exists for — how people are getting in.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $provider = (string) $request->query('provider', '');

        $users = User::query()
            ->withCount(['socialAccounts', 'submissions', 'servers', 'votes'])
            ->with('socialAccounts:id,user_id,provider,nickname,last_login_at')
            ->when($search !== '', fn ($query) => $query->where(function ($where) use ($search) {
                $where->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            // "email" is not a provider but the absence of one: an account with
            // no social row exists because somebody typed six digits from their
            // inbox. So the two branches are exclusive — asked together they
            // describe an account that both has and has not a provider.
            ->when($provider === 'email', fn ($query) => $query->whereDoesntHave('socialAccounts'))
            ->when($provider !== '' && $provider !== 'email', fn ($query) => $query->whereHas(
                'socialAccounts',
                fn ($accounts) => $accounts->where('provider', $provider),
            ))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'provider' => $provider,
            'totals' => [
                'users' => User::count(),
                'with_provider' => User::has('socialAccounts')->count(),
                'code_only' => User::doesntHave('socialAccounts')->count(),
            ],
        ]);
    }

    public function show(User $user): View
    {
        $user->load([
            'socialAccounts',
            'submissions' => fn ($query) => $query->with('game:id,name,slug')->latest('id')->limit(50),
            'servers' => fn ($query) => $query->with('game:id,name,slug')->latest('claimed_at')->limit(50),
            'votes' => fn ($query) => $query->with('server:id,slug,name')->latest('id')->limit(50),
        ]);

        return view('admin.users.show', ['user' => $user]);
    }
}
