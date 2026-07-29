<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\Social\Contracts\SocialProvider;
use App\Services\Auth\Social\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /** Who the bearer of this token is. The frontend calls it on load to restore a session. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('socialAccounts:id,user_id,provider');

        return (new UserResource($user))->response();
    }

    /**
     * Sign out of this browser only.
     *
     * Deletes the token that made the request, not every token the account has:
     * signing out on a laptop must not sign the same person out on their phone.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['signed_out' => true]]);
    }

    /**
     * Which sign-in buttons to draw.
     *
     * All of them, with `available` saying which are switched on. The dialog
     * shows the full menu either way: "we support Discord, it is not configured
     * on this deployment" is information, and a silently missing button is the
     * same picture as a broken one. Adding credentials flips the flag with no
     * frontend change.
     */
    public function providers(SocialProviders $providers): JsonResponse
    {
        return response()->json([
            'data' => collect($providers->all())
                ->map(fn (SocialProvider $provider, string $key) => [
                    'key' => $key,
                    'label' => $provider->label(),
                    'url' => route('api.auth.social.redirect', ['provider' => $key]),
                    'available' => $provider->isConfigured(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
