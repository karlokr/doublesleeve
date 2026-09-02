<?php
/**
 * Aligns the catalogue's vocabularies with TCGplayer's.
 *
 * TCGplayer's model is category -> group -> product -> SKU, where a SKU is
 * product x Printing x Condition x Language. Our combinations already mirror that
 * shape; this script makes the *values* agree too, and pulls per-product card data
 * from TCGplayer's own extendedData rather than a second catalogue's naming.
 *
 * Two deliberate deviations, both documented in docs/operations-pipeline.md:
 *   - "Card Type" and "Stage" are dirty at source (duplicate spellings that differ
 *     only by dash or accent, plus a literal "bASIC" typo). Those are normalised.
 *   - Everything else is taken verbatim.
 *
 *   make align
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

/** TCGplayer's complete Printing vocabulary - verified across 19 groups, all eras. */
const PRINTINGS = [
    'Normal',
    'Holofoil',
    'Reverse Holofoil',
    '1st Edition',
    '1st Edition Holofoil',
    'Unlimited',
    'Unlimited Holofoil',
];

/** Collapse TCGplayer's duplicate spellings. Key = as-found, value = canonical. */
const CARD_TYPE_NORMALISE = [
    'Trainer — Item' => 'Trainer - Item',
    'Trainer - Pokémon Tool' => 'Trainer - Tool',
    'Trainer - Pokemon Tool' => 'Trainer - Tool',
    'Trainer - Pokemon Tool ' => 'Trainer - Tool',
    'Tool' => 'Trainer - Tool',
    'Item' => 'Trainer - Item',
    'Stadium' => 'Trainer - Stadium',
    'Supporter' => 'Trainer - Supporter',
    'Dark' => 'Darkness',
];

const STAGE_NORMALISE = [
    'bASIC' => 'Basic',
    '1' => 'Stage 1',
    'Supporter' => null,   // not a stage; drop it
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Aligning with TCGplayer\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$languages = Language::getLanguages(false);

function everyLang(string $value): array
{
    $out = [];
    foreach (Language::getLanguages(false) as $language) {
        $out[(int) $language['id_lang']] = $value;
    }

    return $out;
}

// ---------------------------------------------------------------------------
// 1. Finish -> Printing
// ---------------------------------------------------------------------------
$groupId = null;
foreach (AttributeGroup::getAttributesGroups($defaultLang) as $group) {
    if (in_array($group['name'], ['Finish', 'Printing'], true)) {
        $groupId = (int) $group['id_attribute_group'];
        break;
    }
}

if ($groupId === null) {
    warn('no Finish/Printing attribute group found');
} else {
    $group = new AttributeGroup($groupId);
    $group->name = everyLang('Printing');
    $group->public_name = everyLang('Printing');
    $group->update();
    line('attribute group renamed to "Printing" (TCGplayer\'s own label)');

    $existing = [];
    foreach (AttributeGroup::getAttributes($defaultLang, $groupId) as $attribute) {
        $existing[$attribute['name']] = (int) $attribute['id_attribute'];
    }

    $position = 0;
    foreach (PRINTINGS as $printing) {
        if (!isset($existing[$printing])) {
            $attribute = new ProductAttribute();
            $attribute->id_attribute_group = $groupId;
            $attribute->name = everyLang($printing);
            $attribute->position = $position;
            $attribute->add();
            line("  printing added: $printing");
        }
        ++$position;
    }

    // Report, don't silently delete: some of these are attached to real stock.
    $strays = array_diff(array_keys($existing), PRINTINGS);
    foreach ($strays as $stray) {
        $inUse = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute_combination
              WHERE id_attribute = ' . (int) $existing[$stray]
        );
        if ($inUse === 0) {
            (new ProductAttribute((int) $existing[$stray]))->delete();
            line("  removed unused non-TCGplayer printing: $stray");
        } else {
            warn("  \"$stray\" is not a TCGplayer printing but is used by $inUse combination(s) - left in place");
        }
    }
}

// ---------------------------------------------------------------------------
// 2. per-product card data, straight from TCGplayer
// ---------------------------------------------------------------------------
function fetchJson(string $url, int $attempts = 4): ?array
{
    for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
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

$featureIds = [];
foreach (Feature::getFeatures($defaultLang) as $feature) {
    $featureIds[$feature['name']] = (int) $feature['id_feature'];
}

$valueCache = [];
function featureValueId(int $idFeature, string $value): ?int
{
    global $valueCache, $defaultLang;
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $key = $idFeature . '|' . mb_strtolower($value);
    if (isset($valueCache[$key])) {
        return $valueCache[$key];
    }

    $rows = Db::getInstance()->executeS(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fv.id_feature_value
          WHERE fv.id_feature = ' . $idFeature . ' AND fvl.id_lang = ' . (int) $defaultLang . '
            AND fvl.value = "' . pSQL($value) . '"'
    );
    if ($rows) {
        return $valueCache[$key] = (int) $rows[0]['id_feature_value'];
    }

    $featureValue = new FeatureValue();
    $featureValue->id_feature = $idFeature;
    $featureValue->custom = false;
    $featureValue->value = everyLang($value);
    $featureValue->add();

    return $valueCache[$key] = (int) $featureValue->id;
}

// Pull every mapped group's products once.
$groups = $db->executeS(
    'SELECT DISTINCT tcgplayer_group_id FROM ' . _DB_PREFIX_ . 'price_source_map
      WHERE tcgplayer_group_id IS NOT NULL'
) ?: [];

$byProductId = [];
foreach ($groups as $row) {
    $data = fetchJson('https://tcgcsv.com/tcgplayer/3/' . (int) $row['tcgplayer_group_id'] . '/products');
    foreach ($data['results'] ?? [] as $product) {
        $extended = [];
        foreach ($product['extendedData'] ?? [] as $entry) {
            $extended[$entry['name']] = $entry['value'];
        }
        $byProductId[(int) $product['productId']] = $extended;
    }
}
line(count($byProductId) . ' TCGplayer products loaded from ' . count($groups) . ' groups');

$mapped = $db->executeS(
    'SELECT p.id_product, m.tcgplayer_product_id
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
      WHERE m.tcgplayer_product_id IS NOT NULL'
) ?: [];

$updated = 0;
$rarities = [];
$normalised = 0;

foreach ($mapped as $row) {
    $extended = $byProductId[(int) $row['tcgplayer_product_id']] ?? null;
    if (!$extended) {
        continue;
    }
    $productId = (int) $row['id_product'];
    $product = new Product($productId);
    if (!Validate::isLoadedObject($product)) {
        continue;
    }

    $assign = [];

    if (!empty($extended['Rarity'])) {
        $assign['Rarity'] = (string) $extended['Rarity'];
        $rarities[(string) $extended['Rarity']] = true;
    }
    if (!empty($extended['Number'])) {
        $assign['Card Number'] = (string) $extended['Number'];
    }
    if (!empty($extended['Card Type'])) {
        $raw = trim((string) $extended['Card Type']);
        $clean = CARD_TYPE_NORMALISE[$raw] ?? $raw;
        if ($clean !== $raw) {
            ++$normalised;
        }
        $assign['Card Type'] = $clean;
    }
    if (!empty($extended['Stage'])) {
        $raw = trim((string) $extended['Stage']);
        $clean = array_key_exists($raw, STAGE_NORMALISE) ? STAGE_NORMALISE[$raw] : $raw;
        if ($clean !== null) {
            if ($clean !== $raw) {
                ++$normalised;
            }
            $assign['Stage'] = $clean;
        }
    }

    foreach ($assign as $featureName => $value) {
        if (!isset($featureIds[$featureName])) {
            continue;
        }
        $idValue = featureValueId($featureIds[$featureName], $value);
        if (!$idValue) {
            continue;
        }
        // addFeaturesToDB() APPENDS - it does not replace. Left alone it gives a
        // card two rarities ("Rare Holo" from the old source plus "Holo Rare" from
        // TCGplayer), which then shows up twice in the facet rail.
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'feature_product
              WHERE id_product = ' . $productId . '
                AND id_feature = ' . (int) $featureIds[$featureName]
        );
        $product->addFeaturesToDB($featureIds[$featureName], $idValue);
    }
    ++$updated;
}

line("$updated products re-tagged from TCGplayer extendedData");
line(count($rarities) . ' distinct TCGplayer rarities now in use: ' . implode(', ', array_keys($rarities)));
if ($normalised) {
    line("$normalised dirty Card Type/Stage values normalised");
}

// ---------------------------------------------------------------------------
// 3. flag rarity values we invented that TCGplayer does not use
// ---------------------------------------------------------------------------
if (isset($featureIds['Rarity'])) {
    $ours = $db->executeS(
        'SELECT fv.id_feature_value, fvl.value,
                (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_product fp
                  WHERE fp.id_feature_value = fv.id_feature_value) AS in_use
           FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
             ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = ' . $defaultLang . '
          WHERE fv.id_feature = ' . $featureIds['Rarity']
    ) ?: [];

    $removed = 0;
    foreach ($ours as $row) {
        if (isset($rarities[$row['value']])) {
            continue;
        }
        if ((int) $row['in_use'] === 0) {
            (new FeatureValue((int) $row['id_feature_value']))->delete();
            ++$removed;
        } else {
            warn('rarity "' . $row['value'] . '" is not TCGplayer vocabulary but is on '
                . (int) $row['in_use'] . ' product(s)');
        }
    }
    line("$removed unused non-TCGplayer rarity values removed");
}

Tools::clearAllCache();
