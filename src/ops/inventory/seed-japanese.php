<?php
/**
 * Seeds Japanese stock: singles and sealed product from TCGplayer's Japan
 * catalogue (category 85), mirrored daily by tcgcsv.com.
 *
 * Data comes prefetched in provisioning/data/seed-products-jp.json: the chase
 * cards and a spread of commons from four sets collectors actually ask for
 * (Shiny Treasure ex, VSTAR Universe, Pokemon Card 151, Tag All Stars), plus
 * their booster boxes and packs.
 *
 * Everything region-shaped is derived, not entered here:
 *   - the products file into Singles / Japanese / <block> / <set> by running
 *     sets-tcgplayer.php Japanese AFTER this seed - the price_source_map rows
 *     written below are what its scoped queries key on
 *   - the Region feature comes from that same run
 *   - card language is the SKU axis, set to Japanese on every combination
 *
 * Prices are TCGplayer market (USD), converted through the same Bank of Canada
 * rate the price engine uses - never a hand-typed exchange rate.
 *
 * Idempotent: products are matched on reference and skipped.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/inventory/seed-japanese.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AdminKernel.php';
require_once __DIR__ . '/../lib/cardname.php';
(new AdminKernel('prod', false))->boot();

const SEED_JSON = '/provisioning/data/seed-products-jp.json';

/**
 * NM and LP only. The Japanese singles market effectively trades in two states -
 * "mint" and "played" - because Japanese cards come out of packs cleaner and are
 * sleeved earlier; the five-step Western ladder would invent stock states this
 * shop will never actually buy.
 */
const CONDITION_LADDER = ['Near Mint' => 1.00, 'Lightly Played' => 0.82];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Seeding Japanese inventory\033[0m\n";

if (!is_readable(SEED_JSON)) {
    warn('seed file missing: ' . SEED_JSON);
    exit(1);
}
$seed = json_decode((string) file_get_contents(SEED_JSON), true);

$db = Db::getInstance();
$languages = Language::getLanguages(false);
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;
$GLOBALS['shopId'] = $shopId;

$usdCad = (float) $db->getValue(
    'SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "USDCAD"'
);
if ($usdCad < 1.0) {
    warn('no USDCAD rate in price_fx - run price-sync first');
    exit(1);
}
line(sprintf('USDCAD %.4f (Bank of Canada)', $usdCad));

// ---------------------------------------------------------------------------
// lookups (same shapes as seed-inventory.php)
// ---------------------------------------------------------------------------
function categoryByName(string $name): ?int
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
          WHERE cl.id_lang = 1 AND cl.name = "' . pSQL($name) . '"'
    );

    return $cache[$name] = $rows ? (int) $rows[0]['id_category'] : null;
}

/** The set category under Singles / Japanese specifically - names can repeat across regions. */
function japaneseSetCategory(string $setName): ?int
{
    static $cache = [];
    if (array_key_exists($setName, $cache)) {
        return $cache[$setName];
    }
    $japanese = categoryByName('Japanese');
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
           JOIN ' . _DB_PREFIX_ . 'category era ON era.id_category = c.id_parent
          WHERE era.id_parent = ' . (int) $japanese . ' AND cl.name = "' . pSQL($setName) . '"'
    );

    return $cache[$setName] = $rows ? (int) $rows[0]['id_category'] : null;
}

function attributeId(string $groupName, string $value): ?int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (Db::getInstance()->executeS(
            'SELECT a.id_attribute, al.name AS value, agl.name AS grp
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                    ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = 1'
        ) ?: [] as $row) {
            $cache[mb_strtolower($row['grp'] . '|' . $row['value'])] = (int) $row['id_attribute'];
        }
    }

    return $cache[mb_strtolower($groupName . '|' . $value)] ?? null;
}

function featureIdByName(string $name): ?int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (Feature::getFeatures(1) as $f) {
            $cache[$f['name']] = (int) $f['id_feature'];
        }
    }

    return $cache[$name] ?? null;
}

function featureValue(string $featureName, string $value): ?int
{
    $idFeature = featureIdByName($featureName);
    if ($idFeature === null || trim($value) === '') {
        return null;
    }
    $existing = (int) Db::getInstance()->getValue(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fv.id_feature_value
          WHERE fv.id_feature = ' . $idFeature . ' AND fvl.id_lang = 1 AND fvl.value = "' . pSQL($value) . '"'
    );
    if ($existing) {
        return $existing;
    }
    $fv = new FeatureValue();
    $fv->id_feature = $idFeature;
    $fv->custom = false;
    foreach (Language::getLanguages(false) as $language) {
        $fv->value[(int) $language['id_lang']] = $value;
    }
    $fv->add();

    return (int) $fv->id;
}

function existingByReference(string $reference): ?int
{
    $id = (int) Db::getInstance()->getValue(
        'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"'
    );

    return $id ?: null;
}

function stockFor(string $seed, int $min, int $max): int
{
    return $min + (crc32($seed) % max(1, $max - $min + 1));
}

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
    foreach (ImageType::getImagesTypes('products') as $type) {
        ImageManager::resize($tmp, $path . '-' . $type['name'] . '.jpg', (int) $type['width'], (int) $type['height']);
    }
    @unlink($tmp);

    return true;
}

// ---------------------------------------------------------------------------
// singles
// ---------------------------------------------------------------------------
$pokemonRoot = categoryByName('Pokémon');
$singlesRoot = categoryByName('Singles');
$sealedRoot = categoryByName('Sealed');
$japaneseRoot = categoryByName('Japanese');
$vocabulary = nameVocabulary('/provisioning/data/pokemon-species.csv', 'name_fr');
$speciesEn = loadSpeciesNames('/provisioning/data/pokemon-species.csv', 'name');

$created = 0;
$skipped = 0;
$imageFails = 0;

foreach ($seed['singles'] as $card) {
    $setCategory = japaneseSetCategory($card['set']);
    if ($setCategory === null) {
        warn('set not in tree (run sets-tcgplayer.php Japanese first): ' . $card['set']);
        continue;
    }

    $reference = 'PKMJP-' . $card['groupId'] . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $card['number']);
    $existing = existingByReference($reference);
    if ($existing !== null) {
        // Repair pass: a product that exists but lost its image fetch (CDN URL
        // shape, timeout) gets the image and nothing else touched.
        if (!Image::getImages(1, $existing)) {
            attachImage($existing, $card['image']) ?: ++$imageFails;
        }
        ++$skipped;
        continue;
    }

    $priceCad = round($card['market'] * $usdCad, 2);

    $product = new Product();
    $product->reference = $reference;
    $product->price = $priceCad;
    $product->id_category_default = $setCategory;
    $product->active = true;
    $product->visibility = 'both';
    $product->id_tax_rules_group = 0;
    $product->minimal_quantity = 1;
    $product->out_of_stock = 0;

    // Titles are DERIVED (composeCardTitle), matching every other card in the
    // shop; the French side localises the species name where one matches.
    $frName = localiseCardName($card['name'], $vocabulary);
    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $cardName = $language['iso_code'] === 'en' ? $card['name'] : $frName;
        $title = composeCardTitle($cardName, $card['set'], (string) $card['number']);
        $product->name[$idLang] = $title;
        $product->link_rewrite[$idLang] = Tools::str2url($title) ?: 'card-' . $reference;
        $product->description[$idLang] = $language['iso_code'] === 'en'
            ? '<p>Japanese-language printing from ' . htmlspecialchars($card['set']) . ', card '
              . htmlspecialchars((string) $card['number']) . '. Graded to TCGplayer condition standards and '
              . 'shipped in a sleeve and toploader.</p>'
            : '<p>Impression en japonais de l\'extension ' . htmlspecialchars($card['set']) . ', carte '
              . htmlspecialchars((string) $card['number']) . '. Évaluée selon les normes d\'état de TCGplayer et '
              . 'expédiée sous protège-carte rigide.</p>';
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
    // Full ancestor chain - parents list only directly associated products.
    $eraId = (int) $db->getValue(
        'SELECT id_parent FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . $setCategory
    );
    $product->addToCategories(array_values(array_unique(array_filter(
        [$setCategory, $eraId, $japaneseRoot, $singlesRoot, $pokemonRoot]
    ))));

    foreach (array_filter([
        'Pokemon' => matchSpecies($card['name'], $speciesEn),
        'Rarity' => $card['rarity'],
        'Card Number' => (string) $card['number'],
    ]) as $featureName => $value) {
        $idFeature = featureIdByName($featureName);
        $idValue = featureValue($featureName, (string) $value);
        if ($idFeature && $idValue) {
            $product->addFeaturesToDB($idFeature, $idValue);
        }
    }

    $languageAttr = attributeId('Card Language', 'Japanese');
    // The printing comes from TCGplayer's subTypeName - nearly every JP chase
    // card is a Holofoil, and an earlier cut stamped them all Normal.
    $printingAttr = attributeId('Printing', $card['printing'] ?? 'Normal');
    $gradingAttr = attributeId('Grading', 'Ungraded');

    $isFirst = true;
    foreach (CONDITION_LADDER as $condition => $multiplier) {
        $conditionAttr = attributeId('Condition', $condition);
        if (!$conditionAttr) {
            continue;
        }
        $combination = new Combination();
        $combination->id_product = (int) $product->id;
        $combination->price = round($priceCad * $multiplier - $priceCad, 2);
        $combination->reference = $reference . '-JP-' . ($condition === 'Near Mint' ? 'NM' : 'LP');
        $combination->default_on = $isFirst ? 1 : null;
        $combination->minimal_quantity = 1;
        $combination->add();
        $combination->setAttributes(array_values(array_filter(
            [$conditionAttr, $languageAttr, $printingAttr, $gradingAttr]
        )));

        $quantity = $priceCad > 200 ? 1
            : ($priceCad > 25 ? stockFor($combination->reference, 1, 4) : stockFor($combination->reference, 2, 12));
        StockAvailable::setQuantity((int) $product->id, (int) $combination->id, $quantity);
        $isFirst = false;
    }

    // Identity: what derive-names and the cutout pipeline key on.
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'card_identity
            (id_product, number, id_category_set, card_language, language_code, date_upd)
         VALUES (' . (int) $product->id . ', "' . pSQL((string) $card['number']) . '", '
            . $setCategory . ', "Japanese", "JP", NOW())
         ON DUPLICATE KEY UPDATE number = VALUES(number), id_category_set = VALUES(id_category_set),
                                 card_language = VALUES(card_language), language_code = VALUES(language_code),
                                 date_upd = NOW()'
    );
    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $cardName = $language['iso_code'] === 'en' ? $card['name'] : $frName;
        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'card_identity_lang (id_product, id_lang, card_name)
             VALUES (' . (int) $product->id . ', ' . $idLang . ', "' . pSQL($cardName) . '")
             ON DUPLICATE KEY UPDATE card_name = VALUES(card_name)'
        );
    }

    // Price engine wiring: market sync and the region-scoped importer both key
    // on this map.
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_source_map
            (reference, kind, tcgplayer_product_id, tcgplayer_group_id, tcgplayer_subtype)
         VALUES ("' . pSQL($reference) . '", "single", ' . (int) $card['productId'] . ', '
            . (int) $card['groupId'] . ', "Normal")
         ON DUPLICATE KEY UPDATE tcgplayer_product_id = VALUES(tcgplayer_product_id),
                                 tcgplayer_group_id = VALUES(tcgplayer_group_id)'
    );

    if (!attachImage((int) $product->id, $card['image'])) {
        ++$imageFails;
    }
    ++$created;
}
line("Japanese singles created: $created (skipped $skipped, image failures $imageFails)");

// ---------------------------------------------------------------------------
// sealed
// ---------------------------------------------------------------------------
$sealedCreated = 0;
foreach ($seed['sealed'] as $item) {
    $category = categoryByName($item['type']);
    if ($category === null) {
        continue;
    }

    $reference = 'SLDJP-' . (int) $item['productId'];
    $existing = existingByReference($reference);
    if ($existing !== null) {
        if (!Image::getImages(1, $existing)) {
            attachImage($existing, $item['image']) ?: ++$imageFails;
        }
        ++$skipped;
        continue;
    }

    $priceCad = round($item['market'] * $usdCad, 2);

    $product = new Product();
    $product->reference = $reference;
    $product->price = $priceCad;
    $product->id_category_default = $category;
    $product->active = true;
    $product->visibility = 'both';
    $product->id_tax_rules_group = 0;
    $product->minimal_quantity = 1;

    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        // The region qualifier is in the name on purpose: two "Pokemon Card 151
        // Booster Box" listings differing only by a facet is a mis-buy waiting
        // to happen, and sealed names are authored, not derived.
        $isEn = $language['iso_code'] === 'en';
        $name = $item['name'] . ($isEn ? ' (Japanese)' : ' (japonais)');
        $product->name[$idLang] = $name;
        $product->link_rewrite[$idLang] = Tools::str2url($name) ?: 'sealed-' . $reference;
        $product->description[$idLang] = $isEn
            ? '<p>Factory sealed Japanese product. Stored in a smoke-free, climate-controlled environment '
              . 'and shipped double-boxed.</p>'
            : '<p>Produit japonais scellé en usine. Conservé dans un environnement climatisé et sans fumée, '
              . 'expédié dans un double emballage.</p>';
        $product->description_short[$idLang] = $isEn
            ? '<p>' . htmlspecialchars($item['type']) . ' &middot; Factory Sealed</p>'
            : '<p>' . htmlspecialchars($item['type']) . ' &middot; Scellé en usine</p>';
    }

    if (!$product->add()) {
        continue;
    }
    $product->addToCategories(array_filter([$category, $sealedRoot, $pokemonRoot]));

    /**
     * Region and language are both recorded, because they answer different
     * questions: Region is which catalogue this came out of, language is what
     * is printed on it. They agree for Japanese stock and would not for, say, a
     * Western catalogue printed in French.
     */
    foreach (array_filter([
        'Set' => $item['set'],
        'Region' => 'Japanese',
        'Card Language' => 'Japanese',
        'Seal Status' => 'Factory Sealed',
    ]) as $featureName => $value) {
        $idFeature = featureIdByName($featureName);
        $idValue = featureValue($featureName, (string) $value);
        if ($idFeature && $idValue) {
            $product->addFeaturesToDB($idFeature, $idValue);
        }
    }

    StockAvailable::setQuantity((int) $product->id, 0, stockFor($reference, 1, 4));

    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_source_map
            (reference, kind, tcgplayer_product_id, tcgplayer_group_id, tcgplayer_subtype)
         VALUES ("' . pSQL($reference) . '", "sealed", ' . (int) $item['productId'] . ', '
            . (int) $item['groupId'] . ', "Normal")
         ON DUPLICATE KEY UPDATE tcgplayer_product_id = VALUES(tcgplayer_product_id)'
    );

    if (!attachImage((int) $product->id, $item['image'])) {
        ++$imageFails;
    }
    ++$sealedCreated;
}
line("Japanese sealed created: $sealedCreated");

Product::flushPriceCache();
Tools::clearAllCache();
