<?php
/**
 * Japanese blocks - the era axis for the Japanese catalogue.
 *
 * This is a SEPARATE list from ERA_ORDER, not a translation of it, and that is the
 * whole reason print region earns a category level under Singles. Japan never had
 * the Wizards of the Coast era at all; its releases were Media Factory's. ADV and
 * PCG are not "EX" under another name, LEGEND is not HeartGold & SoulSilver, and
 * VS and web have no Western counterpart whatsoever. Filed into one flat grid these
 * would need "EX (Western)" beside "ADV (Japanese)" to be readable at all.
 *
 * Names are the block codes collectors actually use in English-language trading -
 * "ADV", "PCG", "LEGEND" - because those are what appear on the cards' set symbols
 * and in every price guide. Translating them would help nobody.
 */
declare(strict_types=1);

/** Newest first, matching ERA_ORDER's convention. */
const ERA_JP_ORDER = [
    'MEGA',
    'Scarlet & Violet',
    'Sword & Shield',
    'Sun & Moon',
    'XY',
    'Black & White',
    'LEGEND',
    'Platinum',
    'Diamond & Pearl',
    'PCG',
    'ADV',
    'e-Card',
    'VS',
    'Neo',
    'Base',
    'Promos & Specials',
];

/**
 * Japanese block names in French.
 *
 * The generation blocks are the video games, so they take the official French
 * titles - the same ones ERA_FR uses, because Épée et Bouclier is Épée et Bouclier
 * whichever region printed it. The block CODES (ADV, PCG, LEGEND, VS, web, e-Card)
 * are set-symbol marks, not words, and are identical in every language.
 */
const ERA_JP_FR = [
    'MEGA' => 'MÉGA',
    'Scarlet & Violet' => 'Écarlate et Violet',
    'Sword & Shield' => 'Épée et Bouclier',
    'Sun & Moon' => 'Soleil et Lune',
    'XY' => 'XY',
    'Black & White' => 'Noir & Blanc',
    'LEGEND' => 'LEGEND',
    'Platinum' => 'Platine',
    'Diamond & Pearl' => 'Diamant & Perle',
    'PCG' => 'PCG',
    'ADV' => 'ADV',
    'e-Card' => 'e-Card',
    'VS' => 'VS',
    'Neo' => 'Neo',
    'Base' => 'Base',
    'Promos & Specials' => 'Promos et spéciales',
];

/**
 * Abbreviation patterns, tried in order - FIRST match wins, so the specific
 * two-letter families sit above the bare block letters they contain.
 *
 * The bare-letter rules exist because the block letter plus a number IS the
 * block: S12a is Sword & Shield set 12a whatever December it shipped in, and
 * resolving it by date filed VSTAR Universe under Scarlet & Violet - the bug
 * this table replaced. Bare letters are trusted only when a DIGIT follows: the
 * lettered starter codes (sA, sPD, MG, SNP, SBC) reuse the same initials across
 * unrelated eras, and every one of them dates correctly through the year
 * windows below, which are non-overlapping for exactly that reason.
 */
const ERA_JP_PATTERNS = [
    ['/^sv/', 'Scarlet & Violet'],
    ['/^sm/', 'Sun & Moon'],
    ['/^s[0-9]/', 'Sword & Shield'],
    ['/^m[0-9]/', 'MEGA'],
    ['/^hxy/', 'XY'],
    ['/^xy/', 'XY'],
    ['/^cp/', 'XY'],
    ['/^bw/', 'Black & White'],
    ['/^bk/', 'Black & White'],
    ['/^dpt/', 'Diamond & Pearl'],
    ['/^dp/', 'Diamond & Pearl'],
    ['/^pt/', 'Platinum'],
    ['/^l[0-9l]/', 'LEGEND'],
];

/**
 * Release-year windows, NON-overlapping.
 *
 * Overlapping windows resolved boundary years to the newer block, and Japanese
 * blocks turn over around New Year: December 2022 releases (S12a, sPD, sK) are
 * late Sword & Shield, not early Scarlet & Violet, and the overlap filed all of
 * them wrong. A window claims the changeover year only when the block actually
 * owned most of it; coded sets on the wrong side of a boundary are caught by
 * their prefix above (m-codes in 2025), which is why MEGA's window can start at
 * 2026.
 */
const ERA_JP_BY_YEAR = [
    [2026, 2100, 'MEGA'],
    [2023, 2025, 'Scarlet & Violet'],
    [2019, 2022, 'Sword & Shield'],
    [2016, 2018, 'Sun & Moon'],
    [2013, 2015, 'XY'],
    [2010, 2012, 'Black & White'],
    [2009, 2009, 'LEGEND'],
    [2008, 2008, 'Platinum'],
    [2006, 2007, 'Diamond & Pearl'],
    [2004, 2005, 'PCG'],
    [2003, 2003, 'ADV'],
    [2001, 2002, 'e-Card'],
    [2000, 2000, 'VS'],
    [1999, 1999, 'Neo'],
    [1996, 1998, 'Base'],
];

/** The French name for a Japanese block, or the English one if it has none. */
function eraJpFrench(string $era): string
{
    return ERA_JP_FR[$era] ?? $era;
}

/**
 * Which block a Japanese set belongs to.
 *
 * Abbreviation first where it is unambiguous, release year otherwise. That order
 * matters at the boundaries: S12a "VSTAR Universe" shipped in December 2022, four
 * months into the Scarlet & Violet window, but its code says Sword & Shield and its
 * code is right.
 */
function resolveEraJp(string $name, string $abbreviation, string $publishedOn): string
{
    $abbr = strtolower(trim($abbreviation));
    if ($abbr !== '') {
        foreach (ERA_JP_PATTERNS as [$pattern, $era]) {
            if (preg_match($pattern, $abbr)) {
                return $era;
            }
        }
    }

    $year = (int) substr($publishedOn, 0, 4);
    foreach (ERA_JP_BY_YEAR as [$from, $to, $era]) {
        if ($year >= $from && $year <= $to) {
            return $era;
        }
    }

    return 'Promos & Specials';
}
