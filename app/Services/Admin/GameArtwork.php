<?php

namespace App\Services\Admin;

use App\Models\Game;
use Illuminate\Http\UploadedFile;

/**
 * The three pictures a game has, and what puts them on disk.
 *
 * Written straight into `public/images/games`, the same directory
 * `games:artwork` fills from Steam — one place to look, one place to back up,
 * and a path the frontend can already resolve. The directory is gitignored, so
 * a deploy neither carries these nor deletes them.
 *
 * Names are derived, never taken from the upload: a filename that arrives from
 * a browser is attacker-controlled, and `../../.env` is a valid one. Slug plus
 * role plus the extension we picked from the MIME type is the whole name.
 */
class GameArtwork
{
    /** Column, and what the picture is for. The order is the order the form shows them in. */
    public const ROLES = [
        'icon_path' => [
            'label' => 'Thumbnail',
            'hint' => 'Square. The rail beside every page, and the favourites list — drawn at 28px, so a logo, not a scene.',
        ],
        'cover_path' => [
            'label' => 'Games list card',
            'hint' => 'Wide, 460×215 like Steam header art. The card in the games catalog.',
        ],
        'hero_path' => [
            'label' => 'Game page banner',
            'hint' => 'Wide and tall enough to crop. Stretched across the top of the game page, behind the title.',
        ],
    ];

    private const DESTINATION = 'images/games';

    /** What a browser will actually display, which is a shorter list than what it will upload. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * Store one upload and return the path to save on the game.
     *
     * The old file is removed only after the new one is written, and only when
     * the name differs — a re-upload of the same format overwrites in place,
     * and deleting first would leave a game with no picture if the write failed.
     */
    public function store(Game $game, string $role, UploadedFile $file): string
    {
        $directory = public_path(self::DESTINATION);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = self::EXTENSIONS[$file->getMimeType()] ?? 'jpg';
        $suffix = $role === 'cover_path' ? '' : '-'.str_replace('_path', '', $role);
        $name = "{$game->slug}{$suffix}.{$extension}";

        $file->move($directory, $name);

        $path = self::DESTINATION."/{$name}";

        if ($game->{$role} && $game->{$role} !== $path) {
            $this->deleteFile($game->{$role});
        }

        return $path;
    }

    /**
     * Forget a picture, and take the file with it.
     *
     * Only when it is ours to delete: a path pointing anywhere but our own
     * directory was typed in by hand and may well be shared with another game,
     * so the row is cleared and the file left alone.
     */
    public function forget(Game $game, string $role): void
    {
        if ($game->{$role}) {
            $this->deleteFile($game->{$role});
        }
    }

    private function deleteFile(string $path): void
    {
        if (! str_starts_with($path, self::DESTINATION.'/')) {
            return;
        }

        $absolute = public_path($path);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
