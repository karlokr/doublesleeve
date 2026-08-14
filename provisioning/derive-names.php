<?php
/**
 * Derives every listing title, in every storefront language.
 *
 * A card title is not something anyone writes. It is a composition of matched
 * facts, and each part has exactly one source:
 *
 *     <card name> — <set name> <collector number>
 *
 *     Charizard — Base Set 004/102        en-US
 *     Dracaufeu — Set de Base 004/102     fr-CA, same card
 *
 * Card name and set name follow the STOREFRONT language. The card's language is a
 * VARIANT, so it is stated per SKU rather than in a name that cannot vary.
 *
 * Replaces rename-products.php, which wrote ONE title into every language slot. The
 * French storefront was still showing "Charizard — Base 4/102" - not merely
 * untranslated, but stale in a format the English side had abandoned - because the
 * script compared only the default language before deciding it had nothing to do.
 *
 * The atoms are persisted to card_identity, so titles can be re-derived without
 * touching the network - which is what the admin save hook does.
 *
 *   make derive-names              refresh from TCGplayer, then re-derive
 *   make derive-names OFFLINE=1    re-derive from stored atoms only
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/cardname.php';

const SPECIES_CSV = '/provisioning/data/pokemon-species.csv';

/**
 * Storefront language iso_code => the species column that serves it.
 *
 * A language with no entry keeps English species names, which is the honest
 * fallback: the alternative is inventing translations.
 */
const SPECIES_COLUMN = ['qc' => 'name_fr', 'fr' => 'name_fr'];

define('OFFLINE', in_array('--offline', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Deriving listing titles\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$languages = Language::getLanguages(false);

// ---------------------------------------------------------------------------
// where the atoms live
// ---------------------------------------------------------------------------
/**
 * The card name is stored per storefront language rather than translated on
 * demand, so species localisation happens exactly once, here, and every other
 * consumer - including the module that re-derives on admin save - just reads it.
 */
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'card_identity (
    id_product      INT UNSIGNED NOT NULL,
    number          VARCHAR(32) NOT NULL DEFAULT "",
    id_category_set INT UNSIGNED NOT NULL DEFAULT 0,
    card_language   VARCHAR(32) NOT NULL DEFAULT "English",
    -- Resolved here so the module that re-derives on admin save does not need a
    -- second copy of the code table to drift out of sync with this one.
    language_code   VARCHAR(8) NOT NULL DEFAULT "EN",
    date_upd        DATETIME NOT NULL,
    PRIMARY KEY (id_product)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');

// Added after the first cut; an install created before it needs the column.
if (!(int) $db->getValue(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = "' . _DB_PREFIX_ . 'card_identity"
        AND column_name = "language_code"'
)) {
    $db->execute('ALTER TABLE ' . _DB_PREFIX_ . 'card_identity
                  ADD COLUMN language_code VARCHAR(8) NOT NULL DEFAULT "EN" AFTER card_language');
}

$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'card_identity_lang (
    id_product INT UNSIGNED NOT NULL,
    id_lang    INT UNSIGNED NOT NULL,
    card_name  VARCHAR(255) NOT NULL,
    PRIMARY KEY (id_product, id_lang)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');

// ---------------------------------------------------------------------------
// refresh the atoms from TCGplayer
// ---------------------------------------------------------------------------
function fetchJson(string $url, int $attempts = 4): ?array
{
    for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'DoubleSleeve/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $status < 400) {
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($attempt < $attempts) {
            sleep($attempt * 2);
        }
    }

    return null;
}

if (!OFFLINE) {
    $catalogue = [];
    foreach ($db->executeS(
        'SELECT DISTINCT tcgplayer_group_id FROM ' . _DB_PREFIX_ . 'price_source_map
          WHERE tcgplayer_group_id IS NOT NULL'
    ) ?: [] as $row) {
        $data = fetchJson('https://tcgcsv.com/tcgplayer/3/' . (int) $row['tcgplayer_group_id'] . '/products');
        foreach ($data['results'] ?? [] as $product) {
            $extended = [];
            foreach ($product['extendedData'] ?? [] as $entry) {
                $extended[$entry['name']] = $entry['value'];
            }
            $catalogue[(int) $product['productId']] = [
                'name' => (string) $product['name'],
                'number' => (string) ($extended['Number'] ?? ''),
            ];
        }
    }
    line(count($catalogue) . ' TCGplayer products loaded');

    /**
     * The card's own language, from the feature. A card with none is not tagged
     * with a guess - an untagged title is the exact ambiguity the tag removes, so
     * the product is reported and skipped instead.
     */
    $rows = $db->executeS(
        'SELECT p.id_product, m.tcgplayer_product_id, g.id_category AS id_category_set,
                (SELECT fvl.value
                   FROM ' . _DB_PREFIX_ . 'feature_product fp
                   JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                        ON fl.id_feature = fp.id_feature AND fl.id_lang = 1 AND fl.name = "Card Language"
                   JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                        ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = 1
                  WHERE fp.id_product = p.id_product LIMIT 1) AS card_language
           FROM ' . _DB_PREFIX_ . 'product p
           JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
           JOIN ' . _DB_PREFIX_ . 'tcg_group_category g ON g.group_id = m.tcgplayer_group_id
          WHERE m.kind = "single"'
    ) ?: [];

    // Species names for every storefront language that has a source for them.
    $vocabByLang = [];
    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $column = SPECIES_COLUMN[strtolower((string) $language['iso_code'])] ?? null;
        $vocabByLang[$idLang] = nameVocabulary(SPECIES_CSV, $column);
        line("species names for lang $idLang: " . count($vocabByLang[$idLang]['species']));
    }

    /**
     * The species facet is re-derived from the same match, for the same reason the
     * title is: it was seeded by taking the card name's first word, so the facet
     * listed "Alolan", "Dark", "Galarian", "Roaring", "Tapu" and "Mr." as Pokémon -
     * and those stayed English in French, being words no species table contains.
     */
    $speciesEnglish = loadSpeciesNames(SPECIES_CSV, 'name');
    $speciesFeature = (int) $db->getValue(
        'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
           JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
          WHERE fl.name = "Pokemon"'
    );
    $speciesValueIds = [];
    if ($speciesFeature) {
        foreach ($db->executeS(
            'SELECT fv.id_feature_value, fvl.value FROM ' . _DB_PREFIX_ . 'feature_value fv
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = 1
              WHERE fv.id_feature = ' . $speciesFeature
        ) ?: [] as $row) {
            $speciesValueIds[(string) $row['value']] = (int) $row['id_feature_value'];
        }
    }

    $stored = 0;
    $unmatched = 0;
    $untagged = [];
    $speciesTagged = 0;
    $speciesCleared = 0;

    foreach ($rows as $row) {
        $source = $catalogue[(int) $row['tcgplayer_product_id']] ?? null;
        if (!$source || trim($source['name']) === '') {
            ++$unmatched;
            continue;
        }
        $language = trim((string) $row['card_language']);
        if ($language === '') {
            $untagged[] = (int) $row['id_product'];
            continue;
        }

        $number = trim($source['number']);
        $cardName = stripBakedNumber($source['name'], $number);
        $productId = (int) $row['id_product'];

        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'card_identity
                (id_product, number, id_category_set, card_language, language_code, date_upd)
             VALUES (' . $productId . ', "' . pSQL($number) . '", ' . (int) $row['id_category_set'] . ',
                     "' . pSQL($language) . '", "' . pSQL(cardLanguageCode($language)) . '", NOW())
             ON DUPLICATE KEY UPDATE number = VALUES(number),
                                     id_category_set = VALUES(id_category_set),
                                     card_language = VALUES(card_language),
                                     language_code = VALUES(language_code),
                                     date_upd = NOW()'
        );

        foreach ($languages as $lang) {
            $idLang = (int) $lang['id_lang'];
            $localised = localiseCardName($cardName, $vocabByLang[$idLang]);
            $db->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'card_identity_lang (id_product, id_lang, card_name)
                 VALUES (' . $productId . ', ' . $idLang . ', "' . pSQL($localised) . '")
                 ON DUPLICATE KEY UPDATE card_name = VALUES(card_name)'
            );
        }
        if ($speciesFeature) {
            $species = matchSpecies($cardName, $speciesEnglish);
            $db->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'feature_product
                  WHERE id_product = ' . $productId . ' AND id_feature = ' . $speciesFeature
            );
            if ($species !== null && isset($speciesValueIds[$species])) {
                $db->execute(
                    'INSERT INTO ' . _DB_PREFIX_ . 'feature_product (id_feature, id_product, id_feature_value)
                     VALUES (' . $speciesFeature . ', ' . $productId . ', ' . $speciesValueIds[$species] . ')'
                );
                ++$speciesTagged;
            } else {
                // Trainers and Energy genuinely have no species, and saying so is
                // better than leaving a stale first-word guess in the facet.
                ++$speciesCleared;
            }
        }

        ++$stored;
    }

    line("card identities stored: $stored");
    line("species tagged: $speciesTagged (no species - Trainer/Energy: $speciesCleared)");
    if ($unmatched) {
        warn("$unmatched products have no TCGplayer match and keep their current title");
    }
    if ($untagged !== []) {
        warn(count($untagged) . ' products have no Card Language feature - run card-language.php');
    }
}

// ---------------------------------------------------------------------------
// compose and write
// ---------------------------------------------------------------------------
$identities = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'card_identity') ?: [];
line(count($identities) . ' card identities on file');

$cardNames = [];
foreach ($db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'card_identity_lang') ?: [] as $row) {
    $cardNames[(int) $row['id_product']][(int) $row['id_lang']] = (string) $row['card_name'];
}

/** Set names per language, read once - a set is shared by hundreds of cards. */
$setNames = [];
foreach ($db->executeS(
    'SELECT id_category, id_lang, name FROM ' . _DB_PREFIX_ . 'category_lang'
) ?: [] as $row) {
    $setNames[(int) $row['id_category']][(int) $row['id_lang']] = (string) $row['name'];
}

/**
 * The description is templated from the same facts, so it is generated per language
 * rather than translated.
 *
 * It was written once at seed time and copied into every language, so the French
 * product page carried an English paragraph under a French title. Machine
 * translating it would be the wrong fix: nothing here is prose, it is four
 * substitutions into a fixed sentence.
 *
 * Keyed by storefront iso_code, falling back to the default language's template.
 */
const DESCRIPTION_TEMPLATES = [
    'en' => [
        'card' => '<p>%1$s from %2$s, card %3$s. Illustrated by %4$s.</p>',
        'unknown_artist' => 'unknown',
        'boilerplate' => '<p>Graded to TCGplayer condition standards and shipped in a sleeve '
            . 'and toploader. Pictures are of the actual card where noted.</p>',
    ],
    'qc' => [
        'card' => '<p>%1$s, extension %2$s, carte %3$s. Illustration : %4$s.</p>',
        'unknown_artist' => 'inconnue',
        'boilerplate' => '<p>Évaluée selon les standards de qualité TCGplayer, expédiée en '
            . 'pochette et toploader. Les photos montrent la carte réelle lorsque indiqué.</p>',
    ],
];

/** Artist per product, for the description. */
$artists = [];
foreach ($db->executeS(
    'SELECT fp.id_product, fvl.value
       FROM ' . _DB_PREFIX_ . 'feature_product fp
       JOIN ' . _DB_PREFIX_ . 'feature_lang fl
            ON fl.id_feature = fp.id_feature AND fl.id_lang = 1 AND fl.name = "Artist"
       JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
            ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = 1'
) ?: [] as $row) {
    $artists[(int) $row['id_product']] = (string) $row['value'];
}

$isoByLang = [];
foreach ($languages as $language) {
    $isoByLang[(int) $language['id_lang']] = strtolower((string) $language['iso_code']);
}

$updated = 0;
$unchanged = 0;
$described = 0;
$descriptionsByProduct = [];

foreach ($identities as $identity) {
    $productId = (int) $identity['id_product'];

    // Loaded WITHOUT an id_lang, so name and link_rewrite stay per-language arrays -
    // loading with one collapses them to strings and update() then writes a single
    // language, which is how the French titles went stale in the first place.
    $product = new Product($productId);
    if (!Validate::isLoadedObject($product)) {
        continue;
    }

    $names = [];
    $rewrites = [];
    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $cardName = $cardNames[$productId][$idLang] ?? ($cardNames[$productId][$defaultLang] ?? '');
        if ($cardName === '') {
            continue 2;
        }
        $setName = $setNames[(int) $identity['id_category_set']][$idLang] ?? '';

        $title = composeCardTitle($cardName, $setName, (string) $identity['number']);
        $names[$idLang] = $title;

        $template = DESCRIPTION_TEMPLATES[$isoByLang[$idLang] ?? ''] ?? DESCRIPTION_TEMPLATES['en'];
        $artist = trim($artists[$productId] ?? '');
        $descriptionsByProduct[$productId][$idLang] = sprintf(
            $template['card'],
            htmlspecialchars($cardName, ENT_QUOTES),
            // The set's own parenthetical qualifier reads badly mid-sentence, and
            // the title above already carries it.
            htmlspecialchars(trim((string) preg_replace('/\s*\([^)]*\)$/', '', $setName)), ENT_QUOTES),
            htmlspecialchars(explode('/', (string) $identity['number'])[0], ENT_QUOTES),
            htmlspecialchars($artist !== '' ? $artist : $template['unknown_artist'], ENT_QUOTES)
        ) . $template['boilerplate'];

        $slug = cardTitleSlug($title);
        $rewrites[$idLang] = $slug === ''
            ? 'card-' . strtolower((string) $product->reference)
            : $slug;
    }

    // The slug is derived too, so it is compared too - checking the title alone
    // left every URL on its first-generated form no matter what changed.
    $current = [];
    foreach ($db->executeS(
        'SELECT id_lang, name, link_rewrite FROM ' . _DB_PREFIX_ . 'product_lang
          WHERE id_product = ' . $productId
    ) ?: [] as $row) {
        $current[(int) $row['id_lang']] = [(string) $row['name'], (string) $row['link_rewrite']];
    }
    $same = true;
    foreach ($names as $idLang => $title) {
        if (($current[$idLang] ?? null) !== [$title, $rewrites[$idLang]]) {
            $same = false;
            break;
        }
    }
    if ($same) {
        ++$unchanged;
        continue;
    }

    $product->name = $names;
    $product->link_rewrite = $rewrites;
    $product->update();
    ++$updated;
}

foreach ($identities as $identity) {
    $productId = (int) $identity['id_product'];
    foreach ($descriptionsByProduct[$productId] ?? [] as $idLang => $html) {
        $db->update(
            'product_lang',
            ['description' => pSQL($html, true)],
            'id_product = ' . $productId . ' AND id_lang = ' . (int) $idLang
        );
        ++$described;
    }
}
line("$described descriptions templated per language");

line("titles rewritten: $updated | already correct: $unchanged");

// ---------------------------------------------------------------------------
// subtitle
// ---------------------------------------------------------------------------
/**
 * Cleared, not re-derived.
 *
 * description_short held the rarity and nothing else, which was a stopgap from
 * before rarity had anywhere better to live. It now sits on the product page badge
 * line, on every cart line and in the data sheet - so keeping it here as well
 * stated the same fact three times on one page. Listing tiles never used the field.
 */
$cleared = 0;
foreach ($identities as $identity) {
    $productId = (int) $identity['id_product'];
    foreach ($languages as $language) {
        $db->update(
            'product_lang',
            ['description_short' => ''],
            'id_product = ' . $productId . ' AND id_lang = ' . (int) $language['id_lang']
        );
    }
    ++$cleared;
}
line("$cleared redundant rarity subtitles cleared");

Product::flushPriceCache();
Tools::clearAllCache();
