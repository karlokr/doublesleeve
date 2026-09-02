<?php
/**
 * Seeds real inventory: Pokémon singles and sealed product with real names,
 * market-derived prices and real images.
 *
 * Data comes from two sources, both fetched ahead of time into
 * provisioning/data/seed-products.json:
 *   - singles: pokemontcg.io (card metadata, artwork, TCGplayer market prices)
 *   - sealed:  tcgcsv.com, a free daily mirror of TCGplayer's own catalogue -
 *              real ETBs, booster boxes and tins rather than invented ones.
 *
 * Singles get one combination per condition they are "stocked" in, priced off the
 * NM market price by the standard condition ladder. That is the pricing model a
 * real card shop runs on, so the seed demonstrates it rather than faking flat prices.
 *
 * Idempotent: products are matched on reference (the canonical SKU) and skipped.
 *
 *   make seed
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AdminKernel.php';
(new AdminKernel('prod', false))->boot();

const SEED_JSON = '/provisioning/data/seed-products.json';

/** Condition ladder: multiplier off the Near Mint market price. */
const CONDITION_LADDER = [
    'Near Mint' => 1.00,
    'Lightly Played' => 0.85,
    'Moderately Played' => 0.70,
    'Heavily Played' => 0.55,
    'Damaged' => 0.40,
];

const SEALED_CATEGORY = [
    'Elite Trainer Box' => 'Elite Trainer Boxes',
    'Booster Box' => 'Booster Boxes',
    'Booster Bundle' => 'Booster Bundles',
    'Booster Pack' => 'Booster Packs',
    'Tin' => 'Tins',
    'Collection Box' => 'Collection & Premium Boxes',
    'Premium Collection' => 'Collection & Premium Boxes',
    'Blister' => 'Blisters & Multi-Packs',
    'Multi-Pack' => 'Blisters & Multi-Packs',
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Seeding inventory\033[0m\n";

if (!is_readable(SEED_JSON)) {
    warn('seed file missing: ' . SEED_JSON);
    exit(1);
}

$seed = json_decode((string) file_get_contents(SEED_JSON), true);
$languages = Language::getLanguages(false);
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;
$homeId = (int) Configuration::get('PS_HOME_CATEGORY');

// ---------------------------------------------------------------------------
// lookups
// ---------------------------------------------------------------------------
$categoryCache = [];
function categoryByName(string $name): ?int
{
    global $categoryCache, $defaultLang;
    if (array_key_exists($name, $categoryCache)) {
        return $categoryCache[$name];
    }
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
          WHERE cl.id_lang = ' . (int) $defaultLang . ' AND cl.name = "' . pSQL($name) . '"'
    );

    return $categoryCache[$name] = $rows ? (int) $rows[0]['id_category'] : null;
}

$featureCache = [];
function featureId(string $name): ?int
{
    global $featureCache, $defaultLang;
    if (!$featureCache) {
        foreach (Feature::getFeatures($defaultLang) as $f) {
            $featureCache[$f['name']] = (int) $f['id_feature'];
        }
    }

    return $featureCache[$name] ?? null;
}

$featureValueCache = [];
/** Find a feature value, creating it if the seed data introduces a new one. */
function featureValueId(string $featureName, string $value): ?int
{
    global $featureValueCache, $defaultLang;
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $idFeature = featureId($featureName);
    if ($idFeature === null) {
        return null;
    }

    $key = $idFeature . '|' . mb_strtolower($value);
    if (array_key_exists($key, $featureValueCache)) {
        return $featureValueCache[$key];
    }

    $rows = Db::getInstance()->executeS(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fv.id_feature_value
          WHERE fv.id_feature = ' . (int) $idFeature . ' AND fvl.id_lang = ' . (int) $defaultLang . '
            AND fvl.value = "' . pSQL($value) . '"'
    );
    if ($rows) {
        return $featureValueCache[$key] = (int) $rows[0]['id_feature_value'];
    }

    $featureValue = new FeatureValue();
    $featureValue->id_feature = $idFeature;
    $featureValue->custom = false;
    foreach (Language::getLanguages(false) as $language) {
        $featureValue->value[(int) $language['id_lang']] = $value;
    }
    $featureValue->add();

    return $featureValueCache[$key] = (int) $featureValue->id;
}

$attributeCache = [];
function attributeId(string $groupName, string $value): ?int
{
    global $attributeCache, $defaultLang;
    if (!$attributeCache) {
        $rows = Db::getInstance()->executeS(
            'SELECT a.id_attribute, al.name AS value, agl.name AS grp
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . (int) $defaultLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . (int) $defaultLang
        );
        foreach ($rows as $row) {
            $attributeCache[mb_strtolower($row['grp'] . '|' . $row['value'])] = (int) $row['id_attribute'];
        }
    }

    return $attributeCache[mb_strtolower($groupName . '|' . $value)] ?? null;
}

// ---------------------------------------------------------------------------
// images
// ---------------------------------------------------------------------------
function attachImage(int $productId, string $url): bool
{
    $tmp = tempnam(sys_get_temp_dir(), 'cc_img');
    $context = stream_context_create(['http' => ['timeout' => 25, 'user_agent' => 'DoubleSleeve/1.0']]);
    $bytes = @file_get_contents($url, false, $context);
    if ($bytes === false || strlen($bytes) < 1024) {
        @unlink($tmp);

        return false;
    }
    file_put_contents($tmp, $bytes);

    $image = new Image();
    $image->id_product = $productId;
    $image->position = Image::getHighestPosition($productId) + 1;
    $image->cover = true;
    if (!$image->add()) {
        @unlink($tmp);

        return false;
    }
    $image->associateTo($GLOBALS['shopId']);

    $path = $image->getPathForCreation();
    if (!ImageManager::resize($tmp, $path . '.jpg')) {
        @unlink($tmp);

        return false;
    }
    // Every registered image type needs its own file or the theme falls back to
    // the "no picture" placeholder at that size.
    foreach (ImageType::getImagesTypes('products') as $type) {
        ImageManager::resize($tmp, $path . '-' . $type['name'] . '.jpg', (int) $type['width'], (int) $type['height']);
    }
    @unlink($tmp);

    return true;
}

// ---------------------------------------------------------------------------
// product creation
// ---------------------------------------------------------------------------
$singlesRoot = categoryByName('Singles');
$sealedRoot = categoryByName('Sealed');
// PrestaShop lists only DIRECTLY associated products, so the game root has to be
// on every product or "all Pokémon" - the one view that spans both regions and all
// three forms - renders empty.
$pokemonRoot = categoryByName('Pokémon');

function existingByReference(string $reference): ?int
{
    $rows = Db::getInstance()->executeS(
        'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"'
    );

    return $rows ? (int) $rows[0]['id_product'] : null;
}

/** Deterministic pseudo-stock so re-seeding gives the same shop. */
function stockFor(string $seed, int $min, int $max): int
{
    return $min + (crc32($seed) % max(1, $max - $min + 1));
}

$created = 0;
$skipped = 0;
$imageFails = 0;

// --- singles ---------------------------------------------------------------
foreach ($seed['singles'] as $index => $card) {
    $setCategory = categoryByName($card['set_category']);
    if ($setCategory === null) {
        warn('unknown set category: ' . $card['set_category']);
        continue;
    }

    $setCode = preg_match('/\(([^)]+)\)$/', $card['set_category'], $m) ? $m[1] : 'PKM';
    $reference = sprintf('PKM-%s-%s', $setCode, preg_replace('/[^A-Za-z0-9]/', '', (string) $card['number']));

    if (existingByReference($reference) !== null) {
        ++$skipped;
        continue;
    }

    $product = new Product();
    $product->reference = $reference;
    $product->price = (float) $card['price'];
    $product->id_category_default = $setCategory;
    $product->active = true;
    $product->visibility = 'both';
    $product->id_tax_rules_group = 0;
    $product->minimal_quantity = 1;
    $product->out_of_stock = 0;

    $description = sprintf(
        '<p>%s from %s, card %s. Illustrated by %s.</p><p>Graded to TCGplayer condition standards '
        . 'and shipped in a sleeve and toploader. Pictures are of the actual card where noted.</p>',
        htmlspecialchars($card['card_name']),
        htmlspecialchars(preg_replace('/\s*\([^)]*\)$/', '', $card['set_category'])),
        htmlspecialchars((string) $card['number']),
        htmlspecialchars($card['artist'] !== '' ? $card['artist'] : 'unknown')
    );

    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $product->name[$idLang] = $card['name'];
        $product->link_rewrite[$idLang] = Tools::str2url($card['name']) ?: 'card-' . $reference;
        $product->description[$idLang] = $description;
        /**
         * No subtitle. Rarity already appears on the product page badge line, on
         * every cart line and in the data sheet, so putting it here as well
         * stated one fact three times on a single page - and it landed directly
         * above the variant selectors, reading like a heading for them.
         * catalog/derive-names.php owns this rule and clears the field.
         */
        $product->description_short[$idLang] = '';
    }

    if (!$product->add()) {
        warn('failed to create ' . $card['name']);
        continue;
    }
    $product->addToCategories(array_filter([$setCategory, $singlesRoot, $pokemonRoot]));

    // features
    $featureMap = array_filter([
        'Pokemon' => $card['supertype'] === 'Pokémon' ? $card['pokemon'] : null,
        'Rarity' => $card['rarity'],
        'Card Number' => (string) $card['number'],
        'Artist' => $card['artist'],
        'Pokemon Type' => $card['types'],
        'Stage' => $card['stage'],
        'Regulation Mark' => $card['regulation_mark'],
        'Release Year' => $card['release_year'],
        'Card Type' => $card['supertype'] === 'Pokémon' ? 'Pokemon' : null,
    ], static fn ($v) => $v !== null && $v !== '');

    foreach ($featureMap as $featureName => $value) {
        $idFeature = featureId($featureName);
        $idValue = featureValueId($featureName, (string) $value);
        if ($idFeature && $idValue) {
            $product->addFeaturesToDB($idFeature, $idValue);
        }
    }

    // combinations: one per condition actually stocked, in the card's language.
    $languageAttr = attributeId('Card Language', 'English');
    $finishAttr = attributeId('Finish', $card['finish']) ?? attributeId('Finish', 'Normal');

    $stockedConditions = ['Near Mint', 'Lightly Played'];
    if (crc32($reference) % 3 === 0) {
        $stockedConditions[] = 'Moderately Played';
    }
    if (crc32($reference) % 7 === 0) {
        $stockedConditions[] = 'Heavily Played';
    }

    $isFirst = true;
    foreach ($stockedConditions as $condition) {
        $conditionAttr = attributeId('Condition', $condition);
        if (!$conditionAttr) {
            continue;
        }

        $combination = new Combination();
        $combination->id_product = (int) $product->id;
        // Price impact is relative to the product's NM base price.
        $combination->price = round(($card['price'] * CONDITION_LADDER[$condition]) - $card['price'], 2);
        $combination->reference = $reference . '-EN-' . strtoupper(substr($condition, 0, 1) . substr(strstr($condition, ' '), 1, 1));
        $combination->default_on = $isFirst ? 1 : null;
        $combination->minimal_quantity = 1;
        $combination->add();
        $combination->setAttributes(array_values(array_filter([$conditionAttr, $languageAttr, $finishAttr])));

        // Cheap cards come in bulk; chase cards are one-of-one.
        $quantity = $card['price'] > 200 ? stockFor($combination->reference, 1, 2)
            : ($card['price'] > 20 ? stockFor($combination->reference, 1, 5) : stockFor($combination->reference, 3, 18));
        StockAvailable::setQuantity((int) $product->id, (int) $combination->id, $quantity);
        $isFirst = false;
    }

    if (!attachImage((int) $product->id, $card['image'])) {
        ++$imageFails;
    }
    ++$created;

    if ($created % 25 === 0) {
        echo "     ... $created products\n";
    }
}
line("singles created: $created (skipped $skipped already present)");

// --- sealed ----------------------------------------------------------------
$sealedCreated = 0;
foreach ($seed['sealed'] as $item) {
    $categoryName = SEALED_CATEGORY[$item['sealed_type']] ?? null;
    $category = $categoryName ? categoryByName($categoryName) : null;
    if ($category === null) {
        continue;
    }

    $reference = 'SLD-' . strtoupper(substr(md5($item['name']), 0, 10));
    if (existingByReference($reference) !== null) {
        ++$skipped;
        continue;
    }

    $product = new Product();
    $product->reference = $reference;
    $product->price = (float) $item['price'];
    $product->id_category_default = $category;
    $product->active = true;
    $product->visibility = 'both';
    $product->id_tax_rules_group = 0;
    $product->minimal_quantity = 1;

    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $product->name[$idLang] = $item['name'];
        $product->link_rewrite[$idLang] = Tools::str2url($item['name']) ?: 'sealed-' . $reference;
        $product->description[$idLang] = '<p>Factory sealed ' . htmlspecialchars(strtolower($item['sealed_type']))
            . '. Stored in a smoke-free, climate-controlled environment and shipped double-boxed.</p>';
        $product->description_short[$idLang] = '<p>' . htmlspecialchars($item['sealed_type']) . ' &middot; Factory Sealed</p>';
    }

    if (!$product->add()) {
        continue;
    }
    $product->addToCategories(array_filter([$category, $sealedRoot, $pokemonRoot]));

    /**
     * Language is stamped HERE, not left to the migration that introduced it.
     *
     * Sealed has no combinations, so it cannot carry the Card Language axis
     * singles use; it holds the same fact as a feature instead. Seeding it at
     * creation is what stops a reprovision from producing sealed stock with no
     * language - the badge and the facet both read this.
     */
    foreach ([
        'Sealed Product Type' => $item['sealed_type'],
        'Seal Status' => 'Factory Sealed',
        'Card Language' => $item['language'] ?? 'English',
    ] as $featureName => $value) {
        $idFeature = featureId($featureName);
        $idValue = featureValueId($featureName, (string) $value);
        if ($idFeature && $idValue) {
            $product->addFeaturesToDB($idFeature, $idValue);
        }
    }

    StockAvailable::setQuantity((int) $product->id, 0, stockFor($reference, 1, 8));

    if (!attachImage((int) $product->id, $item['image'])) {
        ++$imageFails;
    }
    ++$sealedCreated;
}
line("sealed created: $sealedCreated");

if ($imageFails) {
    warn("images that failed to download: $imageFails");
}

// ---------------------------------------------------------------------------
Product::flushPriceCache();
Search::indexation(true);
Tools::clearAllCache();
line('search index and caches refreshed');
