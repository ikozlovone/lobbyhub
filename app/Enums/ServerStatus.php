<?php

namespace App\Enums;

enum ServerStatus: string
{
    /** Answered the last query. */
    case Online = 'online';

    /** Query timed out or was refused. */
    case Offline = 'offline';

    /** Never queried yet — freshly added or discovered. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Unknown => 'Not checked yet',
        };
    }
}
