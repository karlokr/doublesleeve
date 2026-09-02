<?php
/**
 * Which card back belongs on a listing.
 *
 * A buyer of a single wants both faces. The back is not decoration either - for
 * Japanese cards it is part of what identifies the card, because Japan changed
 * its back partway through and a 1990s card with a modern back is a red flag a
 * collector will spot immediately.
 *
 * Three backs are in play:
 *
 *   Western           the blue Poké Ball back, unchanged since 1996 and shared
 *                     by every Western-language printing
 *   Japanese, 1996    "POCKET MONSTERS CARD GAME", the original Japanese back
 *   Japanese, modern  the yellow-bordered "POKÉMON" back
 *
 * Japan switched in 2002 (Bulbapedia dates the current back to that year), which
 * lands INSIDE the e-Card block rather than between blocks - so this resolves on
 * the set's release date, not its era. Sets from 2002 onward get the modern
 * back; everything before it gets the 1996 one.
 *
 * Chinese and Korean printings use their own backs again; they are absent here
 * rather than approximated, and a card in those regions simply gets no back
 * image until one is sourced.
 */
declare(strict_types=1);

const CARD_BACK_DIR = '/provisioning/assets/';

/** The year Japan's current back replaced the Pocket Monsters one. */
const JP_MODERN_BACK_FROM = 2002;

/**
 * Absolute path to the back scan for a card, or null when none is known.
 *
 * @param string $region    Western | Japanese | Chinese
 * @param string $published the set's release date, any parseable prefix of YYYY
 */
function cardBackPath(string $region, string $published = ''): ?string
{
    $year = (int) substr(trim($published), 0, 4);

    $file = match ($region) {
        'Japanese' => ($year > 0 && $year < JP_MODERN_BACK_FROM)
            ? 'card-back-jp-1996.jpg'
            : 'card-back-jp-modern.jpg',
        'Western' => 'card-back.jpg',
        default => null,
    };
    if ($file === null) {
        return null;
    }

    $path = CARD_BACK_DIR . $file;

    return is_file($path) ? $path : null;
}

/** Human label for the back, for alt text and admin listings. */
function cardBackLabel(string $region, string $published = ''): string
{
    $year = (int) substr(trim($published), 0, 4);

    return match ($region) {
        'Japanese' => ($year > 0 && $year < JP_MODERN_BACK_FROM)
            ? 'Japanese card back (1996 Pocket Monsters)'
            : 'Japanese card back',
        default => 'Card back',
    };
}
