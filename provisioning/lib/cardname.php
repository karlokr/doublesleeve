<?php
/**
 * Composes a listing title out of matched card facts.
 *
 * A card title is never authored. It is a composition, and every part has exactly
 * one source, so the title cannot drift from the catalogue:
 *
 *     <card name> — <set name> <collector number>
 *
 *     Charizard — Base Set 004/102        en-US storefront
 *     Dracaufeu — Set de Base 004/102     fr-CA storefront, same card
 *
 * Card name and set name localise to the STOREFRONT language.
 *
 * The card's LANGUAGE is deliberately absent. It is a variant axis, so one product
 * holds an English and a French copy of the same card, and a name can only state
 * one of them. It appears instead on every surface that describes a specific SKU:
 * the tile chip, the cart line, the checkout summary and the product page selector.
 *
 * See docs/information-architecture.md.
 */
declare(strict_types=1);

/**
 * Card language => the code shown in the title.
 *
 * Trade codes, not ISO 639-1: collectors write JP and KR, and a listing that said
 * "(JA)" would read as a typo to the people buying. The Chinese codes follow the
 * same logic - the script is the distinction that matters, so it is spelled out.
 */
const CARD_LANGUAGE_CODES = [
    'English' => 'EN',
    'French' => 'FR',
    'German' => 'DE',
    'Italian' => 'IT',
    'Spanish' => 'ES',
    'Portuguese' => 'PT',
    'Japanese' => 'JP',
    'Korean' => 'KR',
    'Traditional Chinese' => 'ZH-T',
    'Simplified Chinese' => 'ZH-S',
];

function cardLanguageCode(string $language): string
{
    return CARD_LANGUAGE_CODES[trim($language)] ?? strtoupper(substr(trim($language), 0, 2));
}

/**
 * English species name => its name in one target language, longest first.
 *
 * Longest first is what makes replacement safe for the compound names: "Mr. Mime"
 * has to be tried before "Mime", and "Iron Valiant" before "Iron".
 */
function loadSpeciesNames(string $csvPath, string $column): array
{
    if (!is_readable($csvPath)) {
        return [];
    }

    $handle = fopen($csvPath, 'r');
    $header = fgetcsv($handle);
    $map = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }
        $record = array_combine($header, array_pad($row, count($header), ''));
        $english = trim((string) ($record['name'] ?? ''));
        $target = trim((string) ($record[$column] ?? ''));
        if ($english !== '' && $target !== '') {
            $map[$english] = $target;
        }
    }
    fclose($handle);

    uksort($map, static fn ($a, $b) => strlen($b) <=> strlen($a));

    return $map;
}

/**
 * English form prefixes, and what the same form is called in the target language.
 *
 * These are prefixes in English and SUFFIXES in French - "Dark Charizard" is
 * "Dracaufeu Obscur", "Galarian Zapdos" is "Électhor de Galar" - so a straight
 * species swap leaves a title that is half-translated and in the wrong order. The
 * list is closed and small; every one of them appears in the catalogue.
 *
 * Keyed by the species column they accompany, so the vocabulary travels with the
 * name source it belongs to.
 */
const FORM_MODIFIERS = [
    'name_fr' => [
        'Alolan' => "%s d'Alola",
        'Galarian' => '%s de Galar',
        'Hisuian' => '%s de Hisui',
        'Paldean' => '%s de Paldea',
        'Dark' => '%s Obscur',
        'Light' => '%s Lumineux',
    ],
];

/**
 * TCGplayer's parenthetical variant qualifiers, translated.
 *
 * These sit outside the species and outside the set, so neither of those
 * substitutions touched them - a French title read "Noctali VMAX (Alternate Art
 * Secret)", which is the most valuable thing about that listing left in English.
 *
 * The vocabulary is closed and the words are all adjectives describing a card or an
 * illustration, so they agree in the feminine. Ball names, "Jumbo" and "Staff" are
 * printed marks and stay as they are.
 */
const NAME_QUALIFIERS = [
    'name_fr' => [
        'Alternate Art Secret' => 'Illustration Alternative Secrète',
        'Alternate Full Art' => 'Illustration Complète Alternative',
        'Alternate Art' => 'Illustration Alternative',
        'Special Illustration Rare' => 'Illustration Spéciale Rare',
        'Full Art' => 'Illustration Complète',
        'Secret' => 'Secrète',
        'Red Cheeks' => 'Joues Rouges',
        'Yellow Cheeks' => 'Joues Jaunes',
        'Cosmos Holo' => 'Holo Cosmos',
        'Confetti Holo' => 'Holo Confettis',
        'Reverse Holo' => 'Reverse Holo',
        'Non-Holo' => 'Non-Holo',
        'Textured' => 'Texturée',
        'Shiny' => 'Chromatique',
        'Gold' => 'Or',
        'Stamped' => 'Estampillée',
        'Prerelease' => 'Avant-première',
        'Winner' => 'Gagnant',
    ],
];

/**
 * Everything needed to localise a card name into one language.
 *
 * Bundled rather than passed as four positional arguments, because they are one
 * vocabulary and they always travel together.
 *
 * @return array{species: array<string,string>, modifiers: array<string,string>, qualifiers: array<string,string>}
 */
function nameVocabulary(string $csvPath, ?string $column): array
{
    if ($column === null) {
        return ['species' => [], 'modifiers' => [], 'qualifiers' => []];
    }

    return [
        'species' => loadSpeciesNames($csvPath, $column),
        'modifiers' => FORM_MODIFIERS[$column] ?? [],
        'qualifiers' => NAME_QUALIFIERS[$column] ?? [],
    ];
}

/**
 * Translates the species inside a card name, leaving everything else alone.
 *
 *     Charizard VSTAR       ->  Dracaufeu VSTAR
 *     Dark Charizard        ->  Dracaufeu Obscur
 *     Galarian Zapdos V     ->  Électhor de Galar V
 *     Professor's Research  ->  unchanged (no species in it at all)
 *
 * VSTAR / ex / V / GX are brand marks printed identically on French cards, so only
 * the species and its form may move. Trainers and Energy have no species and are
 * returned as printed - they do have official French names, but we have no source
 * for them and inventing one would be worse than leaving it. That fallback is also
 * what keeps "Paldean Student" - a Trainer, not a Tauros - from being mangled into
 * "Student de Paldea": no species matched, so no rule fires.
 */
/**
 * Translates every "(...)" qualifier a card name carries.
 *
 * Applied independently of the species, because Trainers get them too - and the
 * qualifier is often the whole reason a listing is worth what it is.
 */
function localiseQualifiers(string $cardName, array $qualifiers): string
{
    if ($qualifiers === [] || !str_contains($cardName, '(')) {
        return $cardName;
    }

    return (string) preg_replace_callback(
        '/\(([^()]+)\)/u',
        static function (array $match) use ($qualifiers): string {
            $inside = trim($match[1]);
            foreach ($qualifiers as $english => $french) {
                if (strcasecmp($inside, $english) === 0) {
                    return '(' . $french . ')';
                }
            }

            return $match[0];
        },
        $cardName
    );
}

/**
 * The species a card name refers to, or null for Trainers and Energy.
 *
 * The seeded catalogue derived this by taking the FIRST WORD of the card name,
 * which is why the Pokémon facet offered "Alolan", "Dark", "Galarian", "Roaring",
 * "Tapu" and "Mr." as if they were Pokémon - and why they stayed English on the
 * French storefront, being words no translation table has ever heard of.
 */
function matchSpecies(string $cardName, array $species): ?string
{
    foreach ($species as $english => $_) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($english, '/') . '(?![\p{L}\p{N}])/ui';
        if (preg_match($pattern, $cardName)) {
            return $english;
        }
    }

    return null;
}

function localiseCardName(string $cardName, array $vocabulary): string
{
    $cardName = localiseQualifiers($cardName, $vocabulary['qualifiers'] ?? []);

    $species = $vocabulary['species'] ?? [];
    $modifiers = $vocabulary['modifiers'] ?? [];
    if ($cardName === '' || $species === []) {
        return $cardName;
    }

    foreach ($species as $english => $target) {
        /**
         * Hand-rolled boundaries rather than \b.
         *
         * Species names carry punctuation that \b treats as a boundary in its own
         * right - "Farfetch'd", "Mr. Mime", "Ho-Oh", "Type: Null" - so \b both
         * matches inside them and fails at their edges. Requiring a non-alphanumeric
         * neighbour is what stops "Mew" from matching inside "Mewtwo".
         */
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($english, '/') . '(?![\p{L}\p{N}])/ui';
        if (!preg_match($pattern, $cardName, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $start = (int) $match[0][1];
        $end = $start + strlen((string) $match[0][0]);
        $replacement = $target;

        foreach ($modifiers as $modifier => $format) {
            $before = substr($cardName, 0, $start);
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($modifier, '/') . '\s+$/ui', $before, $prefix, PREG_OFFSET_CAPTURE)) {
                $start = (int) $prefix[0][1];
                $replacement = sprintf($format, $target);
                break;
            }
        }

        return substr($cardName, 0, $start) . $replacement . substr($cardName, $end);
    }

    return $cardName;
}

/**
 * Removes the collector number TCGplayer sometimes bakes into the product name.
 *
 * They write it two ways - "Alakazam ex - 201/165" and "Snorlax (11)" - and both
 * would end up duplicated once the title appends the number itself. The
 * parenthesised form is only stripped when it genuinely IS the number, so a real
 * name that happens to end in brackets survives.
 */
function stripBakedNumber(string $cardName, string $number): string
{
    if ($number === '') {
        return trim($cardName);
    }

    $cardName = (string) preg_replace(
        '/\s*[-–—]\s*' . preg_quote($number, '/') . '\s*$/u',
        '',
        $cardName
    );

    $bare = ltrim(explode('/', $number)[0], '0');
    if ($bare !== '') {
        $cardName = (string) preg_replace('/\s*\(0*' . preg_quote($bare, '/') . '\)\s*$/u', '', $cardName);
    }

    return trim($cardName);
}

/**
 * URL slug for a derived title.
 *
 * str2url() can leave a trailing separator behind punctuation, which would be baked
 * into every canonical URL on the site.
 */
function cardTitleSlug(string $title): string
{
    return trim((string) (Tools::str2url($title) ?: ''), '-');
}

function composeCardTitle(string $cardName, string $setName, string $number): string
{
    $title = trim($cardName);
    if (trim($setName) !== '') {
        $title .= ' — ' . trim($setName);
    }
    if (trim($number) !== '') {
        $title .= ' ' . trim($number);
    }

    return $title;
}
