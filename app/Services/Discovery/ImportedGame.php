<?php

namespace App\Services\Discovery;

use App\Enums\QueryProtocol;

/**
 * A game somebody else's catalogue has and this one does not, and the row it
 * becomes.
 *
 * One class because there is more than one place they come from — gamemonitoring's
 * game list, SteamDB's charts — and the policy below has to be the same
 * wherever they arrive from. Two importers each deciding what an imported game
 * is switched to is two answers to a question with one right one.
 *
 * What every source can give: an appid, a slug and a name. What none of them
 * can:
 *
 * `query_protocol` is a guess. A Steam appid all but implies Valve A2S — of
 * the three protocols this monitor speaks it is the only one that fits a game
 * published there — but implied is not measured, and a game whose servers
 * answer something else will simply never verify.
 *
 * `default_port` has no honest source at all. A list of servers is not a list
 * of conventions, so this is Valve's own 27015: wrong for ARK (7777), wrong
 * for Rust (28015), and a submission-form hint either way.
 *
 * So an imported game arrives switched off. Beyond those two it has no
 * artwork, no description and no meta text, and three hundred untouched game
 * pages added to a catalog of forty-six is not a catalog — it is a doorway for
 * a thin-content penalty. Somebody switches each one on after giving it a page.
 */
final readonly class ImportedGame
{
    public function __construct(
        public int $appId,
        public string $slug,
        public string $name,
    ) {}

    /**
     * @param  int  $sortOrder  after everything already in the catalog, in the
     *                          order the source ranked them
     * @param  bool  $active  overriding the switched-off default, for a caller
     *                        that has said it does not want the review
     * @return array<string, mixed>
     */
    public function row(int $sortOrder, bool $active): array
    {
        return [
            'slug' => $this->slug,
            'name' => mb_substr($this->name, 0, 255),
            'steam_appid' => $this->appId,
            'query_protocol' => QueryProtocol::Source,
            'default_port' => 27015,
            'is_active' => $active,
            'sort_order' => $sortOrder,
        ];
    }
}
