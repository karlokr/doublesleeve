<?php
/**
 * Resolves a TCGplayer group name to a Pokémon era.
 *
 * TCGplayer's group API has no series/era field - only a publish date - so era has
 * to be derived. Three signals, in order of reliability:
 *
 *   1. The set-code prefix TCGplayer itself puts in the name ("SV08: Surging
 *      Sparks", "SWSH12: Silver Tempest"). This is TCGplayer's own data and is
 *      unambiguous where present.
 *   2. The set name matched against pokemontcg.io's `series` field, which is a
 *      real era taxonomy covering the sets it catalogues.
 *   3. Name shape - promos, trainer kits, box sets and tins are not part of any
 *      numbered era and collectors do not look for them there.
 *
 * Anything still unresolved falls back to its release decade rather than being
 * silently dropped, so every group lands somewhere a shopper can reach.
 */
declare(strict_types=1);

/** Era display names, newest first - this array IS the storefront ordering. */
const ERA_ORDER = [
    'Mega Evolution',
    'Scarlet & Violet',
    'Sword & Shield',
    'Sun & Moon',
    'XY',
    'Black & White',
    'HeartGold & SoulSilver',
    'Platinum',
    'Diamond & Pearl',
    'EX',
    'e-Card',
    'Neo',
    'Gym',
    'Base / WotC',
    'Promos & Specials',
];

/**
 * Era names in French.
 *
 * Most eras ARE the video games, so these are the official French titles rather
 * than translations: "Scarlet & Violet" ships in France as "Écarlate et Violet".
 * The two that are our own coinage - "Base / WotC" and "Promos & Specials" - are
 * simply translated, and "XY", "EX", "Neo", "Gym" and "e-Card" are brand marks that
 * are identical in both.
 *
 * Cross-checked against the série field on Poképédia's set pages, which is where
 * data/pokepedia-sets.csv comes from.
 */
const ERA_FR = [
    'Mega Evolution' => 'Méga-Évolution',
    'Scarlet & Violet' => 'Écarlate et Violet',
    'Sword & Shield' => 'Épée et Bouclier',
    'Sun & Moon' => 'Soleil et Lune',
    'XY' => 'XY',
    'Black & White' => 'Noir & Blanc',
    'HeartGold & SoulSilver' => 'HeartGold & SoulSilver',
    'Platinum' => 'Platine',
    'Diamond & Pearl' => 'Diamant & Perle',
    'EX' => 'EX',
    'e-Card' => 'e-Card',
    'Neo' => 'Neo',
    'Gym' => 'Gym',
    'Base / WotC' => 'Base / WotC',
    'Promos & Specials' => 'Promos et spéciales',
];

/** The French name for an era, or the English one if it has none. */
function eraFrench(string $era): string
{
    return ERA_FR[$era] ?? $era;
}

/**
 * Set-code prefixes TCGplayer writes into group names. Declared longest-first so
 * "SVE" wins over "SV" and "SWSH" over "SM".
 *
 * TCGplayer is not consistent about the separator - all of "SV08: Surging
 * Sparks", "SV: Paldean Fates", "XY - Evolutions", "SM Base Set" and "EX Dragon"
 * occur - so the matcher accepts a colon, a dash or a plain space, with an
 * optional set number attached to the code.
 */
const ERA_PREFIXES = [
    'SWSH' => 'Sword & Shield',
    'HGSS' => 'HeartGold & SoulSilver',
    'MEE' => 'Mega Evolution',
    'SVE' => 'Scarlet & Violet',
    'ME' => 'Mega Evolution',
    'SV' => 'Scarlet & Violet',
    'SM' => 'Sun & Moon',
    'XY' => 'XY',
    'BW' => 'Black & White',
    'DP' => 'Diamond & Pearl',
    'PL' => 'Platinum',
    'HS' => 'HeartGold & SoulSilver',
    'EX' => 'EX',
];

/**
 * pokemontcg.io series names that differ from the era label we display.
 *
 * "Other" is deliberately absent: it is pokemontcg.io's own dumping ground and
 * holds real era sets (Legendary Collection, Southern Islands) alongside genuine
 * oddities, so it is resolved by release date instead of being trusted.
 */
const SERIES_ALIASES = [
    'base' => 'Base / WotC',
    'gym' => 'Gym',
    'neo' => 'Neo',
    'ecard' => 'e-Card',
    'np' => 'Promos & Specials',
    'pop' => 'Promos & Specials',
    'nintendo' => 'Promos & Specials',
];

/** Era by release year, for sets no other signal resolves. */
const ERA_BY_YEAR = [
    [1998, 2002, 'Base / WotC'],
    [2003, 2003, 'e-Card'],
    [2004, 2006, 'EX'],
    [2007, 2008, 'Diamond & Pearl'],
    [2009, 2009, 'Platinum'],
    [2010, 2011, 'HeartGold & SoulSilver'],
    [2012, 2013, 'Black & White'],
    [2014, 2016, 'XY'],
    [2017, 2019, 'Sun & Moon'],
    [2020, 2022, 'Sword & Shield'],
    [2023, 2024, 'Scarlet & Violet'],
    [2025, 2030, 'Mega Evolution'],
];

/** Words that mark a group as a promo/product line rather than a numbered set. */
const PROMO_MARKERS = [
    'promo', 'trainer kit', 'box set', 'tin', 'collection box', 'blister',
    'starter set', 'battle academy', 'trick or trade', 'my first battle',
    'first partner', 'best of game', 'quick construction', 'deck exclusive',
    'miscellaneous', 'world championship', 'prize pack', 'player placement',
];

/**
 * Normalise for comparison. TCGplayer writes "Diamond and Pearl" where
 * pokemontcg.io writes "Diamond & Pearl", and that single difference was enough
 * to drop whole eras into the unmatched bucket.
 */
function eraNormalise(string $value): string
{
    $value = strtolower(trim($value));
    // Curly apostrophe must be the real character, not an escape in single quotes.
    $value = str_replace([' and ', "\u{2019}", "'"], [' & ', '', ''], $value);
    // Fold accents: TCGplayer writes "Pokemon GO", pokemontcg.io "Pokémon GO",
    // and eraKey() would otherwise drop the é entirely and never match.
    $value = strtr($value, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    ]);

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

/**
 * Comparison key: punctuation-insensitive.
 *
 * The two catalogues disagree constantly on punctuation for the same set -
 * "HeartGold SoulSilver" vs "HeartGold & SoulSilver", "Hidden Fates: Shiny Vault"
 * vs "Hidden Fates Shiny Vault". Collapsing to alphanumerics makes those equal
 * without a per-set exception list.
 */
function eraKey(string $value): string
{
    return preg_replace('/[^a-z0-9]/', '', eraNormalise($value)) ?? '';
}

/** Strip a set-code prefix and any parenthetical qualifier from a group name. */
function eraBaseName(string $name): string
{
    // Separator may be a colon, hyphen or the em dash pokemontcg.io uses (HS—Unleashed).
    $clean = preg_replace('/^[A-Za-z0-9&]{1,6}[0-9]*\s*(?::|-|\x{2014})\s*/u', '', trim($name)) ?? $name;
    $clean = preg_replace('/\s*\([^)]*\)\s*$/', '', $clean) ?? $clean;

    return trim($clean);
}

/**
 * @param array<string,string> $seriesByName lowercased pokemontcg.io set name => series
 */
function resolveEra(string $name, string $publishedOn, array $seriesByName): string
{
    $name = trim($name);

    // 1. TCGplayer's own set-code prefix, in any of the separator styles it uses.
    foreach (ERA_PREFIXES as $code => $era) {
        if (preg_match('/^' . preg_quote($code, '/') . '[0-9]*\s*(?::|-|\s)\s*\S/i', $name)) {
            return $era;
        }
    }

    // 2. pokemontcg.io series for this set name. Try the full name, the name with
    //    its set-code prefix stripped, and the parent set of a sub-collection
    //    ("Hidden Fates: Shiny Vault" -> "Hidden Fates").
    $base = eraBaseName($name);
    $parent = str_contains($name, ':') ? trim(explode(':', $name, 2)[0]) : '';
    $candidates = [$name, $base, $base . ' set', preg_replace('/\s+set$/i', '', $base), $parent];

    foreach ($candidates as $candidate) {
        $key = $candidate === null || $candidate === '' ? '' : eraKey($candidate);
        if ($key === '' || !isset($seriesByName[$key])) {
            continue;
        }
        $seriesKey = eraKey($seriesByName[$key]);

        if (isset(SERIES_ALIASES[$seriesKey])) {
            return SERIES_ALIASES[$seriesKey];
        }
        foreach (ERA_ORDER as $era) {
            if (eraKey($era) === $seriesKey) {
                return $era;
            }
        }
        break; // series known but unmapped (e.g. "Other") - fall through to date
    }

    // 3. Promos, kits and boxed products belong to no numbered era.
    $lower = strtolower($name);
    foreach (PROMO_MARKERS as $marker) {
        if (str_contains($lower, $marker)) {
            return 'Promos & Specials';
        }
    }

    // 4. Nothing matched - place by release date so it is still reachable.
    $year = (int) substr($publishedOn, 0, 4);
    foreach (ERA_BY_YEAR as [$from, $to, $era]) {
        if ($year >= $from && $year <= $to) {
            return $era;
        }
    }

    return 'Promos & Specials';
}

/** Load pokemontcg.io set name => series from the sets CSV. */
function loadSeriesMap(string $csvPath): array
{
    if (!is_readable($csvPath)) {
        return [];
    }
    $handle = fopen($csvPath, 'r');
    $header = fgetcsv($handle);
    $map = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 2) {
            continue;
        }
        $record = array_combine($header, array_pad($row, count($header), ''));
        $series = trim((string) $record['series']);
        $setName = (string) $record['set_name'];
        // Index under both the raw name and its prefix-stripped form, so
        // "HS-Unleashed" in this CSV still matches "Unleashed" from TCGplayer.
        foreach ([$setName, eraBaseName($setName)] as $variant) {
            $key = eraKey($variant);
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = $series;
            }
        }
    }
    fclose($handle);

    return $map;
}

/**
 * The name a shopper should see.
 *
 * TCGplayer prefixes many groups with their release code - "SV04: Paradox Rift".
 * That code is catalogue plumbing, not a set name, and repeating it on every tile
 * and breadcrumb is noise. It is stripped for display and preserved separately by
 * setCode() so nothing is lost.
 *
 * Only the "CODE:" and "CODE - " forms are stripped. The bare-space form must be
 * left alone: "SM Base Set" and "XY Base Set" would both collapse to "Base Set"
 * and collide with the 1999 set of that name.
 */
function setDisplayName(string $name): string
{
    $stripped = preg_replace('/^[A-Z][A-Za-z0-9]{0,5}[0-9]*\s*(?::|\s-\s)\s*/', '', trim($name));

    return trim((string) $stripped) !== '' ? trim((string) $stripped) : trim($name);
}

/** The release code stripped by setDisplayName(), or '' if the name carries none. */
function setCode(string $name): string
{
    return preg_match('/^([A-Z][A-Za-z0-9]{0,5}[0-9]*)\s*(?::|\s-\s)/', trim($name), $m) ? $m[1] : '';
}
