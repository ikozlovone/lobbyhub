<?php

namespace App\Services\Catalog;

use App\Models\Server;

/**
 * What language a server's community speaks.
 *
 * No protocol reports this. Not A2S, not the Steam Web API, not the rules a
 * Rust server publishes — every key we have ever seen across the catalog is in
 * `ServerInfo`, and none of them is a language. So it is read off the only
 * evidence there is: what the owner wrote in the server's own name and blurb.
 *
 * Two signals, in order of how much they can be trusted:
 *
 *  1. **Script.** A name written in Cyrillic is a Russian-speaking server, and
 *     no amount of context changes that. Same for Hangul, kana, Thai, Greek,
 *     Hebrew and Arabic. This is close to certain and costs one regex.
 *  2. **A tag the owner wrote.** `[RU]`, `(DE)`, `|TR|` at the start of a Latin
 *     name is a deliberate signal to players, and it is the convention every
 *     Rust server list has trained owners into.
 *
 * Anything else returns null and the row simply does not appear. That is the
 * point of the design: a Latin name proves nothing — half of them are English,
 * the rest are Turkish, Polish, Portuguese or German written without a tag —
 * and guessing "English" from the absence of evidence would make the field a
 * decoration rather than a fact. Country is not used for the same reason: the
 * Russian-speaking servers in this catalog are mostly hosted in Germany and
 * Finland, because that is where the cheap boxes are.
 */
class ServerLanguage
{
    /**
     * Script ranges that identify a language on sight.
     *
     * Han is deliberately absent: the same characters carry Chinese and Japanese,
     * and Japanese is caught by its kana instead. A name in Han alone is left
     * unanswered rather than guessed at.
     */
    private const SCRIPTS = [
        'ru' => ['Russian', '\x{0400}-\x{04FF}'],
        'ko' => ['Korean', '\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}'],
        'ja' => ['Japanese', '\x{3040}-\x{30FF}'],
        'th' => ['Thai', '\x{0E00}-\x{0E7F}'],
        'el' => ['Greek', '\x{0370}-\x{03FF}'],
        'he' => ['Hebrew', '\x{0590}-\x{05FF}'],
        'ar' => ['Arabic', '\x{0600}-\x{06FF}'],
    ];

    /**
     * Tags owners write to say who a server is for.
     *
     * Region markers — EU, NA, AS, OCE, WW — are absent on purpose: they say
     * where the machine is, not what is spoken on it, and "[EU]" covers two
     * dozen languages. US and AU are left out on the same principle even though
     * the guess would usually be right.
     */
    private const TAGS = [
        'ru' => 'Russian',
        'ua' => 'Ukrainian',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'br' => 'Portuguese',
        'tr' => 'Turkish',
        'nl' => 'Dutch',
        'cz' => 'Czech',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'sv' => 'Swedish',
        'se' => 'Swedish',
        'no' => 'Norwegian',
        'dk' => 'Danish',
        'fi' => 'Finnish',
        'en' => 'English',
        'eng' => 'English',
        'cn' => 'Chinese',
        'zh' => 'Chinese',
        'jp' => 'Japanese',
        'kr' => 'Korean',
        'vn' => 'Vietnamese',
        'id' => 'Indonesian',
        'th' => 'Thai',
    ];

    /** @return array{code: string, name: string}|null */
    public function for(Server $server): ?array
    {
        return $this->detect($server->name, $server->motd, $server->description);
    }

    /**
     * The whole of the logic, over plain strings.
     *
     * Separate from `for()` so it stays a pure function of text: it needs no
     * database, no model and no booted application, and a language facet — which
     * would have to run this over a whole catalog — would not want any of them.
     *
     * The name, the title the server broadcasts and its blurb all count as
     * evidence, because any of the three can be the one carrying it. Only the
     * name is read for a tag: a bracketed code inside a paragraph of prose is
     * not a claim about who the server is for.
     *
     * @return array{code: string, name: string}|null
     */
    public function detect(?string $name, ?string $motd = null, ?string $description = null): ?array
    {
        $text = trim(implode(' ', array_filter([$name, $motd, $description])));

        if ($text === '') {
            return null;
        }

        return $this->fromScript($text) ?? $this->fromTag($name ?? '');
    }

    /** @return array{code: string, name: string}|null */
    private function fromScript(string $text): ?array
    {
        foreach (self::SCRIPTS as $code => [$name, $range]) {
            if (preg_match('/['.$range.']/u', $text) === 1) {
                return ['code' => $code, 'name' => $name];
            }
        }

        return null;
    }

    /**
     * A bracketed code, and only at the very start.
     *
     * Anywhere else it is as likely to be part of a name — "NO LIMIT", "X5 [PVP]",
     * a clan tag — as a claim about language.
     *
     * @return array{code: string, name: string}|null
     */
    private function fromTag(string $name): ?array
    {
        if (preg_match('/^\s*[\[\(\|]\s*([A-Za-z]{2,3})\s*[\]\)\|]/', $name, $matches) !== 1) {
            return null;
        }

        $tag = mb_strtolower($matches[1]);

        return isset(self::TAGS[$tag])
            ? ['code' => $tag, 'name' => self::TAGS[$tag]]
            : null;
    }
}
