<?php
/**
 * Seeds Base Set (Shadowless) stock.
 *
 * TCGplayer keeps `Base Set` (group 604) and `Base Set (Shadowless)` (group 1663)
 * as parallel groups holding the same cards at very different values. Without stock
 * in both, the parallel-set model is untested and the shadowless badge never
 * renders - so this seeds the marquee shadowless cards alongside their shadowed
 * counterparts.
 *
 * Note the reference scheme: PKM-BSS-<number>, using TCGplayer's group
 * abbreviation. Reusing "BS" would collide with the shadowed card of the same
 * number and silently share its prices.
 *
 *   make seed-shadowless
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const GROUP_ID = 1663;
const SET_CATEGORY = 'Base Set (Shadowless)';
const ABBREV = 'BSS';
const TOP_N = 12;

const CONDITION_LADDER = [
    'Near Mint' => 1.00,
    'Lightly Played' => 0.85,
    'Moderately Played' => 0.70,
    'Heavily Played' => 0.55,
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Seeding " . SET_CATEGORY . "\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;
$languages = Language::getLanguages(false);

$usdCad = (float) $db->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "USDCAD"');
if ($usdCad <= 0) {
    warn('no USD/CAD rate cached - run `make price-sync` first');
    exit(1);
}

$categoryId = (int) $db->getValue(
    'SELECT id_category FROM ' . _DB_PREFIX_ . 'category_lang
      WHERE id_lang = ' . $defaultLang . ' AND name = "' . pSQL(SET_CATEGORY) . '"'
);
$singlesId = (int) $db->getValue(
    'SELECT id_category FROM ' . _DB_PREFIX_ . 'category_lang
      WHERE id_lang = ' . $defaultLang . ' AND name = "Singles"'
);
if (!$categoryId || !$singlesId) {
    warn('Base Set (Shadowless) or Singles category missing - run `make sets-align`');
    exit(1);
}

function fetchJson(string $url, int $attempts = 4): ?array
{
    for ($i = 1; $i <= $attempts; ++$i) {
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
        sleep($i * 2);
    }

    return null;
}

$products = fetchJson('https://tcgcsv.com/tcgplayer/3/' . GROUP_ID . '/products')['results'] ?? [];
$priceRows = fetchJson('https://tcgcsv.com/tcgplayer/3/' . GROUP_ID . '/prices')['results'] ?? [];
if (!$products || !$priceRows) {
    warn('could not fetch TCGplayer group ' . GROUP_ID);
    exit(1);
}

$prices = [];
foreach ($priceRows as $row) {
    if (!empty($row['marketPrice'])) {
        $prices[(int) $row['productId']][(string) $row['subTypeName']] = (float) $row['marketPrice'];
    }
}

// Marquee cards first - the shadowless premium is what makes the distinction matter.
$candidates = [];
foreach ($products as $product) {
    $extended = [];
    foreach ($product['extendedData'] ?? [] as $entry) {
        $extended[$entry['name']] = $entry['value'];
    }
    if (empty($extended['Number']) || empty($prices[(int) $product['productId']])) {
        continue;
    }
    $candidates[] = [
        'product' => $product,
        'extended' => $extended,
        'top' => max($prices[(int) $product['productId']]),
    ];
}
usort($candidates, static fn ($a, $b) => $b['top'] <=> $a['top']);
$candidates = array_slice($candidates, 0, TOP_N);

// --- attribute lookups ------------------------------------------------------
$attributes = [];
foreach ($db->executeS(
    'SELECT a.id_attribute, al.name AS value, agl.name AS grp
       FROM ' . _DB_PREFIX_ . 'attribute a
       JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $defaultLang . '
       JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $defaultLang
) ?: [] as $row) {
    $attributes[$row['grp']][$row['value']] = (int) $row['id_attribute'];
}
$englishId = $attributes['Card Language']['English'] ?? null;

$featureIds = [];
foreach (Feature::getFeatures($defaultLang) as $feature) {
    $featureIds[$feature['name']] = (int) $feature['id_feature'];
}

function featureValueId(int $idFeature, string $value, int $defaultLang): ?int
{
    if (trim($value) === '') {
        return null;
    }
    $existing = Db::getInstance()->getValue(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fv.id_feature_value
          WHERE fv.id_feature = ' . $idFeature . ' AND fvl.id_lang = ' . $defaultLang . '
            AND fvl.value = "' . pSQL($value) . '"'
    );
    if ($existing) {
        return (int) $existing;
    }
    $featureValue = new FeatureValue();
    $featureValue->id_feature = $idFeature;
    $featureValue->custom = false;
    foreach (Language::getLanguages(false) as $language) {
        $featureValue->value[(int) $language['id_lang']] = $value;
    }
    $featureValue->add();

    return (int) $featureValue->id;
}

function attachImage(int $productId, string $url, int $shopId): bool
{
    $tmp = tempnam(sys_get_temp_dir(), 'cc_sl');
    $bytes = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 25, 'user_agent' => 'DoubleSleeve/1.0'],
    ]));
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
    $image->associateTo($shopId);
    $path = $image->getPathForCreation();
    ImageManager::resize($tmp, $path . '.jpg');
    foreach (ImageType::getImagesTypes('products') as $type) {
        ImageManager::resize($tmp, $path . '-' . $type['name'] . '.jpg', (int) $type['width'], (int) $type['height']);
    }
    @unlink($tmp);

    return true;
}

function stockFor(string $seed, int $min, int $max): int
{
    return $min + (crc32($seed) % max(1, $max - $min + 1));
}

// ---------------------------------------------------------------------------
$created = 0;
$skipped = 0;
$skus = 0;

foreach ($candidates as $candidate) {
    $product = $candidate['product'];
    $extended = $candidate['extended'];
    $productId = (int) $product['productId'];

    $number = preg_replace('/[^A-Za-z0-9]/', '', explode('/', (string) $extended['Number'])[0]);
    $number = ltrim($number, '0') ?: '0';
    $reference = 'PKM-' . ABBREV . '-' . $number;

    if ($db->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"')) {
        ++$skipped;
        continue;
    }

    $available = [];
    foreach ($prices[$productId] as $subtype => $usd) {
        if (isset($attributes['Printing'][$subtype])) {
            $available[$subtype] = $usd * $usdCad;
        }
    }
    if (!$available) {
        continue;
    }

    $base = round(min($available), 2);
    $name = sprintf('%s — Base Set (Shadowless) %s', $product['name'], (string) $extended['Number']);

    $newProduct = new Product();
    $newProduct->reference = $reference;
    $newProduct->price = $base;
    $newProduct->id_category_default = $categoryId;
    $newProduct->active = true;
    $newProduct->visibility = 'both';
    $newProduct->id_tax_rules_group = 0;
    $newProduct->minimal_quantity = 1;

    foreach ($languages as $language) {
        $idLang = (int) $language['id_lang'];
        $newProduct->name[$idLang] = $name;
        $newProduct->link_rewrite[$idLang] = Tools::str2url($name) ?: 'card-' . $reference;
        $newProduct->description[$idLang] = '<p>' . htmlspecialchars((string) $product['name'])
            . ' from the <strong>Shadowless</strong> print run of Base Set — the earlier printing, '
            . 'identifiable by the absence of a drop shadow to the right of the artwork frame. '
            . 'Distinct from, and generally more valuable than, the shadowed Unlimited printing.</p>';
        $newProduct->description_short[$idLang] = '<p>' . htmlspecialchars((string) ($extended['Rarity'] ?? ''))
            . ' &middot; Shadowless</p>';
    }

    if (!$newProduct->add()) {
        continue;
    }
    $newProduct->addToCategories([$categoryId, $singlesId]);

    foreach ([
        'Set' => SET_CATEGORY,
        'Rarity' => (string) ($extended['Rarity'] ?? ''),
        'Card Number' => (string) $extended['Number'],
        'Card Type' => (string) ($extended['Card Type'] ?? ''),
        'Stage' => (string) ($extended['Stage'] ?? ''),
        'Print Run' => 'Shadowless',
        'Release Year' => '1999',
    ] as $featureName => $value) {
        if (!isset($featureIds[$featureName]) || trim((string) $value) === '') {
            continue;
        }
        $idValue = featureValueId($featureIds[$featureName], (string) $value, $defaultLang);
        if ($idValue) {
            $newProduct->addFeaturesToDB($featureIds[$featureName], $idValue);
        }
    }

    $isDefault = true;
    foreach ($available as $subtype => $printingPrice) {
        $stocked = ['Near Mint', 'Lightly Played'];
        if (crc32($reference . $subtype) % 3 === 0) {
            $stocked[] = 'Moderately Played';
        }

        foreach ($stocked as $condition) {
            $printingId = $attributes['Printing'][$subtype] ?? null;
            $conditionId = $attributes['Condition'][$condition] ?? null;
            if (!$printingId || !$conditionId) {
                continue;
            }

            $combination = new Combination();
            $combination->id_product = (int) $newProduct->id;
            $combination->price = round(($printingPrice * CONDITION_LADDER[$condition]) - $base, 2);
            $combination->reference = $reference . '-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $subtype), 0, 4))
                . '-' . strtoupper(preg_replace('/[^A-Z]/', '', ucwords($condition)));
            $combination->default_on = $isDefault ? 1 : null;
            $combination->minimal_quantity = 1;
            $combination->add();
            $combination->setAttributes(array_values(array_filter([$printingId, $conditionId, $englishId])));

            StockAvailable::setQuantity((int) $newProduct->id, (int) $combination->id,
                $printingPrice > 400 ? stockFor($combination->reference, 1, 2) : stockFor($combination->reference, 1, 4));
            $isDefault = false;
            ++$skus;
        }
    }

    if (!empty($product['imageUrl'])) {
        attachImage((int) $newProduct->id, str_replace('_200w', '_in_1000x1000', (string) $product['imageUrl']), $shopId);
    }

    // Keep the price engine able to reprice these.
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_source_map
            (reference, kind, pokemontcg_card_id, tcgplayer_product_id, tcgplayer_group_id, variant_key, tcgplayer_subtype)
         VALUES ("' . pSQL($reference) . '", "single", "", ' . $productId . ', ' . GROUP_ID . ', "", "")
         ON DUPLICATE KEY UPDATE tcgplayer_product_id = VALUES(tcgplayer_product_id)'
    );

    ++$created;
}

line("shadowless cards created: $created (skipped $skipped), $skus SKUs");

Product::flushPriceCache();
Tools::clearAllCache();
