<?php
/**
 * Gives sealed product a Card Language, so it can be badged and filtered.
 *
 * Sealed already carried the fact - as `Region: Western|Japanese` - but under a
 * different name from the one singles use, and singles carry it as a COMBINATION
 * attribute. Two vocabularies for one question meant the cart chip pipeline,
 * which reads the attribute, found nothing on a sealed line, and the language
 * facet covered half the catalogue.
 *
 * Region is kept. It answers a different question: which catalogue a product
 * comes out of - different sets, different numbering, different release schedule
 * - and a Western catalogue can be printed in several languages. Language is
 * added ON TOP rather than folded into it.
 *
 * A FEATURE, not an attribute, because sealed has no variants: an Elite Trainer
 * Box is English or it is Japanese, and the Japanese one is a different product
 * with a different price, not a second SKU of the same one. This is also how
 * TCGplayer files it - language is the product line ("Pokemon" vs "Pokemon
 * Japan"), a level ABOVE the group, never a variant axis.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/migrations/sealed-card-language.php
 *   ... --dry   report what would change and write nothing
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const FEATURE = 'Card Language';
const FEATURE_FR = 'Langue de la carte';

/**
 * Region answers "which catalogue", language answers "what does it say".
 *
 * The mapping is only a DEFAULT for stock we hold today: every Western sealed
 * item in the catalogue is an English print. A French or German box is a real
 * thing and would be Western too, so this fills the gap rather than defining it
 * - once such a box is stocked its language is recorded at intake and this
 * script leaves it alone.
 */
const LANGUAGE_BY_REGION = [
    'Japanese' => 'Japanese',
    'Chinese' => 'Simplified Chinese',
    'Western' => 'English',
];

const VALUE_FR = [
    'English' => 'Anglais',
    'Japanese' => 'Japonais',
    'Simplified Chinese' => 'Chinois simplifié',
];

$dry = in_array('--dry', $argv ?? [], true);

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Card Language for sealed product\033[0m\n";
if ($dry) {
    line('DRY RUN - nothing is written');
}

$db = Db::getInstance();
$languages = Language::getLanguages(false);

/**
 * The feature carries the same NAME as the attribute group singles use.
 *
 * They are different tables and the facet builder addresses them separately
 * (`feat:` versus `ag:`), so the shopper reads one label for one question
 * wherever they are standing.
 */
$idFeature = (int) $db->getValue(
    'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
       JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
      WHERE fl.name = "' . pSQL(FEATURE) . '"'
);
if (!$idFeature) {
    if ($dry) {
        line('would create the "' . FEATURE . '" feature');
        $idFeature = -1;
    } else {
        $feature = new Feature();
        foreach ($languages as $language) {
            $idLang = (int) $language['id_lang'];
            $feature->name[$idLang] = $idLang === 1 ? FEATURE : FEATURE_FR;
        }
        $feature->add();
        $idFeature = (int) $feature->id;
        line('created the "' . FEATURE . '" feature (id ' . $idFeature . ')');
    }
} else {
    line('feature "' . FEATURE . '" already exists (id ' . $idFeature . ')');
}

/** Feature values are shared, so one lookup-or-create per language value. */
$valueCache = [];
function valueId(int $idFeature, string $value, bool $dry): int
{
    global $valueCache, $db, $languages;
    if (isset($valueCache[$value])) {
        return $valueCache[$value];
    }
    $id = (int) $db->getValue(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = 1
          WHERE fv.id_feature = ' . $idFeature . ' AND fvl.value = "' . pSQL($value) . '"'
    );
    if ($id) {
        return $valueCache[$value] = $id;
    }
    if ($dry) {
        return $valueCache[$value] = -1;
    }

    $featureValue = new FeatureValue();
    $featureValue->id_feature = $idFeature;
    $featureValue->custom = false;
    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $featureValue->value[$idLang] = $idLang === 1 ? $value : (VALUE_FR[$value] ?? $value);
    }
    $featureValue->add();

    return $valueCache[$value] = (int) $featureValue->id;
}

/**
 * Every product with no combinations - which is exactly the sealed shelf.
 *
 * Keyed on the absence of variants rather than on the Sealed category, so an
 * accessory or anything else sold as a single SKU is covered by the same pass
 * instead of needing its own.
 */
$rows = $db->executeS(
    'SELECT p.id_product, pl.name,
            (SELECT fvl.value
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                    ON fl.id_feature = fp.id_feature AND fl.id_lang = 1 AND fl.name = "Region"
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = 1
              WHERE fp.id_product = p.id_product LIMIT 1) AS region,
            (SELECT COUNT(*)
               FROM ' . _DB_PREFIX_ . 'feature_product fp
              WHERE fp.id_product = p.id_product AND fp.id_feature = ' . max($idFeature, 0) . ') AS already
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1
      WHERE p.active = 1
        AND NOT EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa
                         WHERE pa.id_product = p.id_product)
      ORDER BY p.id_product'
) ?: [];

$stamped = 0;
$skipped = 0;
$noRegion = [];
$counts = [];

foreach ($rows as $row) {
    $productId = (int) $row['id_product'];
    if ((int) $row['already'] > 0) {
        ++$skipped;
        continue;
    }

    $region = (string) ($row['region'] ?? '');
    $language = LANGUAGE_BY_REGION[$region] ?? null;
    if ($language === null) {
        // No region to read it from: named rather than guessed, so it can be
        // recorded deliberately instead of defaulting to English by accident.
        $noRegion[] = $productId . ' ' . substr((string) $row['name'], 0, 44);
        continue;
    }

    $counts[$language] = ($counts[$language] ?? 0) + 1;
    if ($dry) {
        continue;
    }

    $idValue = valueId($idFeature, $language, false);
    $product = new Product($productId);
    $product->addFeaturesToDB($idFeature, $idValue);
    ++$stamped;
}

foreach ($counts as $language => $count) {
    line(sprintf('%-20s %d product(s)', $language, $count));
}
if ($skipped) {
    line($skipped . ' already carried a language, left alone');
}
foreach ($noRegion as $miss) {
    warn('no Region, so no language: ' . $miss);
}
line($dry ? 'dry run complete' : $stamped . ' product(s) stamped');

if (!$dry && $stamped) {
    // The layered index is what the facet reads; a stamp it has not seen is a
    // filter value that does not exist yet.
    line('rebuilding the layered filter index');
    Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_filter_block');
    line('done - run `make facets` if the rail needs the new value adding');
}
