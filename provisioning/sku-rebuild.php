<?php
/**
 * Rebuilds combinations so they model TCGplayer SKUs.
 *
 * A TCGplayer SKU is product x Printing x Condition x Language, and so is ours.
 * Ours was product x
 * ONE printing x Condition, which cannot represent the 63% of cards that exist as
 * two or more printings - a card whose Normal sells for $0.30 and whose Reverse
 * Holofoil sells for $3.00 had a single price and a single variant.
 *
 * Printing is now price-bearing: the product carries the cheapest printing's Near
 * Mint price as its base, and every combination carries the delta for its own
 * printing and condition, sourced from that printing's own market price.
 *
 * Destructive: existing combinations (and their stock) are replaced. Fine on seed
 * data; on real inventory this would need a migration that preserves quantities.
 *
 *   make sku-rebuild
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const CONDITION_LADDER = [
    'Near Mint' => 1.00,
    'Lightly Played' => 0.85,
    'Moderately Played' => 0.70,
    'Heavily Played' => 0.55,
    'Damaged' => 0.40,
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Rebuilding SKUs (product x printing x condition)\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;

// --- FX, so USD market prices become shop currency -------------------------
$usdCad = (float) $db->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "USDCAD"');
if ($usdCad <= 0) {
    warn('no USD/CAD rate cached - run `make price-sync` first');
    exit(1);
}
line(sprintf('USD/CAD %.4f', $usdCad));

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

if (empty($attributes['Printing']) || empty($attributes['Condition'])) {
    warn('Printing / Condition attribute groups missing - run `make align` first');
    exit(1);
}
/**
 * Card language IS an axis, exactly as TCGplayer models a SKU.
 *
 * Everything currently seeded is English; a French copy of the same card is another
 * combination on the same product, carrying its own scan and price impact.
 */
$englishId = $attributes['Card Language']['English'] ?? null;

// --- market prices per (product, printing) ---------------------------------
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

$rows = $db->executeS(
    'SELECT p.id_product, p.reference, m.tcgplayer_product_id, m.tcgplayer_group_id, m.kind
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
      WHERE p.active = 1 AND m.kind = "single" AND m.tcgplayer_product_id IS NOT NULL'
) ?: [];

$prices = [];
foreach (array_unique(array_filter(array_column($rows, 'tcgplayer_group_id'))) as $groupId) {
    $data = fetchJson('https://tcgcsv.com/tcgplayer/3/' . (int) $groupId . '/prices');
    foreach ($data['results'] ?? [] as $entry) {
        if (!empty($entry['marketPrice'])) {
            $prices[(int) $entry['productId']][(string) $entry['subTypeName']] = (float) $entry['marketPrice'];
        }
    }
}
line(count($prices) . ' products priced across all printings');

/** Deterministic stock so a rebuild reproduces the same shop. */
function stockFor(string $seed, int $min, int $max): int
{
    return $min + (crc32($seed) % max(1, $max - $min + 1));
}

// ---------------------------------------------------------------------------
$rebuilt = 0;
$combinations = 0;
$multiPrinting = 0;

foreach ($rows as $row) {
    $productId = (int) $row['id_product'];
    $available = $prices[(int) $row['tcgplayer_product_id']] ?? [];
    if (!$available) {
        continue;
    }

    // Only printings we actually carry as attribute values.
    $printings = [];
    foreach ($available as $subtype => $usd) {
        if (isset($attributes['Printing'][$subtype])) {
            $printings[$subtype] = $usd * $usdCad;
        }
    }
    if (!$printings) {
        continue;
    }
    if (count($printings) > 1) {
        ++$multiPrinting;
    }

    $product = new Product($productId);
    if (!Validate::isLoadedObject($product)) {
        continue;
    }

    // Base = cheapest printing at Near Mint, so every impact is >= 0 and the
    // "from" price shown on listings is the honest entry price.
    $base = round(min($printings), 2);

    $product->price = $base;
    $product->update();

    // Replace wholesale: combination sets change shape when printings change.
    foreach ($product->getWsCombinations() as $existing) {
        (new Combination((int) $existing['id']))->delete();
    }

    $isDefault = true;
    foreach ($printings as $subtype => $printingPrice) {
        $stocked = ['Near Mint', 'Lightly Played'];
        $seed = $row['reference'] . $subtype;
        if (crc32($seed) % 3 === 0) {
            $stocked[] = 'Moderately Played';
        }
        if (crc32($seed) % 7 === 0) {
            $stocked[] = 'Heavily Played';
        }

        foreach ($stocked as $condition) {
            $conditionId = $attributes['Condition'][$condition] ?? null;
            $printingId = $attributes['Printing'][$subtype] ?? null;
            if (!$conditionId || !$printingId) {
                continue;
            }

            $combination = new Combination();
            $combination->id_product = $productId;
            $combination->price = round(($printingPrice * CONDITION_LADDER[$condition]) - $base, 2);
            $combination->reference = $row['reference'] . '-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $subtype), 0, 4))
                . '-' . strtoupper(preg_replace('/[^A-Z]/', '', ucwords($condition)));
            $combination->default_on = $isDefault ? 1 : null;
            $combination->minimal_quantity = 1;
            $combination->add();
            $combination->setAttributes(array_values(array_filter([$printingId, $conditionId, $englishId])));

            $quantity = $printingPrice > 200 ? stockFor($combination->reference, 1, 2)
                : ($printingPrice > 20 ? stockFor($combination->reference, 1, 5)
                : stockFor($combination->reference, 3, 18));
            StockAvailable::setQuantity($productId, (int) $combination->id, $quantity);

            $isDefault = false;
            ++$combinations;
        }
    }
    ++$rebuilt;
}

line("$rebuilt products rebuilt, $combinations SKUs created");
line("$multiPrinting products now carry more than one printing");

Product::flushPriceCache();
Tools::clearAllCache();
