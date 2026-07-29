<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in visitor, as the frontend sees themselves.
 *
 * Only ever returned to the account it describes — the email is here because
 * a person is entitled to see which address they signed in with, not because
 * it is public. Nothing in the catalog renders another user from this shape.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'providers' => $this->whenLoaded(
                'socialAccounts',
                fn () => $this->socialAccounts->pluck('provider')->all(),
            ),
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
