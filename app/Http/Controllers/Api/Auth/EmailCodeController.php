<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\Accounts;
use App\Services\Auth\LoginCodes;
use App\Services\Auth\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email sign-in: ask for a code, then send it back.
 *
 * Both steps are the same door for a new visitor and a returning one. Nothing
 * here says whether an address already has an account — that answer belongs to
 * whoever reads the mailbox, not to whoever can type into the form.
 */
class EmailCodeController extends Controller
{
    public function store(Request $request, LoginCodes $codes): JsonResponse
    {
        // Shape only, no DNS lookup: a resolver call would put this endpoint's
        // latency in someone else's hands, and a mistyped domain is caught by
        // the mail never arriving anyway.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $codes->issue($email, $request->ip());

        return response()->json([
            'data' => [
                'email' => $email,
                'expires_in' => (int) config('auth.codes.ttl'),
                'resend_in' => (int) config('auth.codes.cooldown'),
            ],
        ], 202);
    }

    public function verify(
        Request $request,
        LoginCodes $codes,
        Accounts $accounts,
        Sessions $sessions,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $codes->consume($email, trim($validated['code']));

        // Only now, once the mailbox is proved, does an account come into
        // existence — a failed attempt leaves no trace of the address.
        $user = $accounts->forEmail($email);

        return response()->json([
            'data' => [
                'token' => $sessions->start($user),
                'user' => new UserResource($user),
            ],
        ], 201);
    }
}
