<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar_url', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Provider identities that resolve to this account. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Servers this account has claimed. */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Servers this account put in the catalog through the form.
     *
     * Not the same set as `servers()`: that one is ownership, proved with the
     * server's own token. Submitting only means somebody typed an address.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Server::class, 'submitted_by_user_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * A display name from an address.
     *
     * Nobody signing in with a one-time code stops to type a name, and
     * "user_8f21" reads worse than the local part they picked themselves.
     */
    public static function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $name = trim(preg_replace('/[._\-+]+/', ' ', $local) ?? '');

        return $name === '' ? 'Player' : Str::limit(Str::title($name), 60, '');
    }
}
