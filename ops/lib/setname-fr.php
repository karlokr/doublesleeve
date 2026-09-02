<?php
/**
 * Derives a French set name when no wiki has one.
 *
 * The requirement is that EVERY set carries a French name, and roughly a fifth of
 * the catalogue is product nobody ever printed in French: McDonald's promo runs,
 * trainer kits, blister exclusives, "Miscellaneous Cards & Products". There is no
 * official name to look up, so one is composed - and composed by rule rather than
 * hand-written, so next year's "McDonald's Promos 2027" is handled without an edit.
 *
 * These are DERIVED, and the CSV records them as such. An official name always
 * wins; this only ever fills a gap.
 *
 * Order matters: the most specific pattern must be tried first, or "SWSH01: Sword
 * & Shield Base Set" is eaten by the bare "(.+) Base Set" rule before the era-aware
 * one sees it.
 */
declare(strict_types=1);

/**
 * Recurring nouns, so a composed name reads like the rest of the catalogue rather
 * than like four separate translators had a go at it.
 */
const SET_WORDS_FR = [
    'Radiant Collection' => 'Collection Radieuse',
    'Classic Collection' => 'Collection Classique',
    'Shiny Vault' => 'Chambre Chromatique',
    'Trainer Gallery' => 'Galerie des Dresseurs',
    'Galarian Gallery' => 'Galerie de Galar',
    'Promo Cards' => 'Cartes promo',
    'Base Set' => 'Set de Base',
    'Energies' => 'Énergies',
    'Promos' => 'Promos',
    'Promo' => 'Promo',
];

/**
 * Whole names with no official French equivalent and no pattern to catch them.
 *
 * Deliberately a short list: anything that can be composed by rule is, so this
 * holds only the genuinely one-off names. Where a French release DID exist and the
 * wikis simply lack it, the name here is the released one.
 */
const SET_NAMES_FR = [
    /**
     * Poképédia is the authority on these and it titles them in English -
     * "Gym Challenge", "Legendary Collection", "Southern Islands", "Base Set 2" -
     * which means that IS their French name, the same way "Jungle" and "Team
     * Rocket" are. Gym Heroes has no Poképédia entry, so it follows its sibling
     * rather than getting a translation the shelf never used.
     */
    'Gym Heroes' => 'Gym Heroes',
    'e-Reader Sample Cards' => "Cartes d'exemple e-Reader",
    'Jumbo Cards' => 'Cartes Jumbo',
    'League & Championship Cards' => 'Cartes de Ligue et de Championnat',
    'Deck Exclusives' => 'Exclusivités de Deck',
    'Blister Exclusives' => 'Exclusivités Blister',
    'World Championship Decks' => 'Decks des Championnats du Monde',
    'Battle Academy' => 'Académie de Combat',
    'Prize Pack Series Cards' => 'Cartes des Packs de Récompense',
    'Trading Card Game Classic' => 'Jeu de Cartes à Collectionner Classic',
    'First Partner Pack' => 'Pack Premier Partenaire',
    'Miscellaneous Cards & Products' => 'Cartes et Produits Divers',
    'Best of Game' => 'Best of Game',
    // Identical in French, but stated explicitly: an unresolved set and a set whose
    // name simply does not change must not look the same to the resolver.
    'Arceus' => 'Arceus',
    'Rumble' => 'Rumble',
    'Ash vs Team Rocket Deck Kit' => 'Kit de Decks Sacha contre la Team Rocket',
    // Fragments that only ever appear inside a composed name ("<X> Promos"), so
    // they are resolved recursively rather than matched as whole set names.
    'Alternate Art' => 'Illustration Alternative',
    'Pikachu World Collection' => 'Collection Mondiale Pikachu',
    'Professor Program' => 'Programme Professeur',
    'Player Placement Trainer' => 'Placement des Joueurs',
    'Countdown Calendar' => "Calendrier de l'Avent",
];

/**
 * Names settled by hand because the two wikis disagree with each other.
 *
 * Consulted BEFORE either source. This is not for improving on a wiki - it is for
 * families they split down the middle, where taking each answer as it comes puts
 * two spellings of the same thing side by side in one era.
 *
 * POP Series is the case: Bulbapedia has "POP Série" for five of the nine,
 * Poképédia has "POP Series" for the other four, and the storefront listed both.
 */
function overrideFrenchSetName(string $english): ?string
{
    if (preg_match('/^POP Series (\d+)$/u', trim($english), $m)) {
        return 'POP Série ' . $m[1];
    }

    return null;
}

/**
 * @param callable(string):string $resolve resolves a nested set or era name to
 *                                         French, so composed names reuse official
 *                                         parts wherever they exist
 */
function deriveFrenchSetName(string $english, callable $resolve): ?string
{
    $english = trim($english);
    if ($english === '') {
        return null;
    }

    if (isset(SET_NAMES_FR[$english])) {
        return SET_NAMES_FR[$english];
    }

    // "Battle Academy 2024", "Ash vs Team Rocket Deck Kit (JP Exclusive)"
    if (preg_match('/^(.+?)\s+(\d{4})$/u', $english, $m) && isset(SET_NAMES_FR[$m[1]])) {
        return SET_NAMES_FR[$m[1]] . ' ' . $m[2];
    }
    if (preg_match('/^(.+?)\s*\(JP Exclusive\)$/u', $english, $m)) {
        $base = deriveFrenchSetName($m[1], $resolve) ?? $m[1];

        return $base . ' (exclusivité JP)';
    }

    // Promo runs by year, and the anniversary one-offs among them.
    if (preg_match("/^McDonald's Promos (\d{4})$/u", $english, $m)) {
        return "Promos McDonald's " . $m[1];
    }
    if (preg_match("/^McDonald's (\d+)(?:st|nd|rd|th) Anniversary Promos$/u", $english, $m)) {
        return "Promos McDonald's " . $m[1] . 'e anniversaire';
    }

    if (preg_match('/^Trick or Trade BOOster Bundle(\s+\d{4})?$/u', $english, $m)) {
        return 'Lot de boosters Trick or Trade' . ($m[1] ?? '');
    }
    if (preg_match('/^First Partner Collection (\d{4})$/u', $english, $m)) {
        return 'Collection Premier Partenaire ' . $m[1];
    }

    /**
     * Trainer kits name two Pokémon, and those DO translate - "HGSS Trainer Kit:
     * Gyarados & Raichu" is a kit for Léviator, not for a word nobody in France
     * uses. The species swap is the caller's job via $resolve.
     */
    if (preg_match('/^(.*?)\s*Trainer Kit(\s+\d+)?:\s*(.+)$/u', $english, $m)) {
        $context = trim($m[1] . ($m[2] ?? ''));
        $pair = str_replace(' & ', ' et ', trim($m[3]));

        return trim('Kit du Dresseur ' . $context) . ' : ' . $resolve($pair);
    }
    if (preg_match('/^(.*?)\s*Training Kit\s*(\d+)?\s*(Blue|Gold)$/u', $english, $m)) {
        $colour = $m[3] === 'Blue' ? 'Bleu' : 'Or';

        return trim("Kit d'entraînement " . trim($m[1] . ' ' . ($m[2] ?? ''))) . ' ' . $colour;
    }

    // "<Set>: <Sub-collection>" - the sub-collection is a fixed noun, the set is
    // resolved so an official name is reused rather than re-derived.
    if (preg_match('/^(.+?):\s*(Radiant Collection|Classic Collection|Shiny Vault|Galarian Gallery)$/u', $english, $m)) {
        return $resolve($m[1]) . ' : ' . SET_WORDS_FR[$m[2]];
    }
    if (preg_match('/^(.+?)\s+Trainer Gallery$/u', $english, $m)) {
        return $resolve($m[1]) . ' – ' . SET_WORDS_FR['Trainer Gallery'];
    }

    // "<Era> Promo Cards" / "<Era> Base Set" / "<Era> Energies" / "<X> Promos".
    if (preg_match('/^(.+?)\s+Promo Cards$/u', $english, $m)) {
        return SET_WORDS_FR['Promo Cards'] . ' ' . $resolve($m[1]);
    }
    if (preg_match('/^(.+?)\s+Base Set$/u', $english, $m)) {
        return SET_WORDS_FR['Base Set'] . ' ' . $resolve($m[1]);
    }
    if (preg_match('/^(.+?)\s+Energies$/u', $english, $m)) {
        return SET_WORDS_FR['Energies'] . ' ' . $resolve($m[1]);
    }
    if (preg_match('/^(.+?)\s+Promos?$/u', $english, $m)) {
        return SET_WORDS_FR['Promos'] . ' ' . $resolve($m[1]);
    }

    return null;
}
