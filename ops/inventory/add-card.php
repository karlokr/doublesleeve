<?php
/**
 * Adds ONE card to stock, end to end.
 *
 * This is the shape of the whole platform: you have a physical card in hand, you
 * match it to its catalogue identity, and everything else - name in every
 * language, set, features, scan, SKU, price wiring - derives from the match.
 * The admin photo-match flow (modules/cryptocards_copies) is this operation with
 * a camera in front of it; this is the same operation from the command line.
 *
 * It exists because the alternative was absurd: adding one card meant editing a
 * seed JSON, running a seeder, then running the set importer - which re-homed
 * 277 unrelated products to place one. A selling platform's unit of work is one
 * copy of one card, so the tool's unit of work is too. Nothing here touches any
 * other product.
 *
 *   add-card.php --group=2545 --number=SWSH075
 *   add-card.php --group=23601 --number=347/190 --qty=2 --condition="Lightly Played"
 *   add-card.php --group=604 --number=004/102 --language=English --printing="Holofoil"
 *
 * The group id is TCGplayer's (see ops/data/tcgplayer-groups*.csv); the region,
 * set category, market price, printing and image all follow from it. The price
 * engine takes over maintenance on its next run via the price_source_map row
 * written here.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AdminKernel.php';
require_once __DIR__ . '/../lib/cardname.php';
require_once __DIR__ . '/../lib/cutout.php';
require_once __DIR__ . '/../lib/cardback.php';
(new AdminKernel('prod', false))->boot();

const CONDITION_LADDER = [
    'Near Mint' => 1.00, 'Lightly Played' => 0.85, 'Moderately Played' => 0.70,
    'Heavily Played' => 0.55, 'Damaged' => 0.40,
];

function line(string $s): void { echo "   + $s\n"; }
function fail(string $s): never { echo "   ! $s\n"; exit(1); }

$options = getopt('', ['group:', 'number:', 'qty::', 'condition::', 'language::', 'printing::']);
$groupId = (int) ($options['group'] ?? 0);
$number = trim((string) ($options['number'] ?? ''));
$qty = max(1, (int) ($options['qty'] ?? 1));
$condition = (string) ($options['condition'] ?? 'Near Mint');
if ($groupId <= 0 || $number === '') {
    fail('usage: add-card.php --group=<tcgplayer group id> --number=<collector number> [--qty=N] [--condition=...] [--language=...] [--printing=...]');
}
if (!isset(CONDITION_LADDER[$condition])) {
    fail("unknown condition \"$condition\" - one of: " . implode(', ', array_keys(CONDITION_LADDER)));
}

echo "\n\033[1m== Add card: group $groupId, number $number\033[0m\n";

$db = Db::getInstance();

// ---------------------------------------------------------------------------
// region and set follow from the group - never entered by hand
// ---------------------------------------------------------------------------
$region = null;
foreach (['Western' => '/provisioning/data/tcgplayer-groups.csv',
          'Japanese' => '/provisioning/data/tcgplayer-groups-jp.csv'] as $name => $csv) {
    if (($fh = @fopen($csv, 'r')) === false) {
        continue;
    }
    fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if ((int) $row[0] === $groupId) {
            $region = $name;
            break 2;
        }
    }
    fclose($fh);
}
if ($region === null) {
    fail("group $groupId is in neither groups CSV - refresh ops/data first");
}
$tcgCategory = $region === 'Japanese' ? 85 : 3;

$setCategoryId = (int) $db->getValue(
    'SELECT id_category FROM ' . _DB_PREFIX_ . 'tcg_group_category WHERE group_id = ' . $groupId
);
if (!$setCategoryId) {
    fail("group $groupId has no set category - run: make sets-align" . ($region === 'Japanese' ? '-jp' : ''));
}
$setNames = [];
foreach ($db->executeS(
    'SELECT id_lang, name FROM ' . _DB_PREFIX_ . 'category_lang
      WHERE id_category = ' . $setCategoryId . ' AND id_shop = 1'
) ?: [] as $row) {
    $setNames[(int) $row['id_lang']] = (string) $row['name'];
}
line("region $region, set \"" . ($setNames[1] ?? '?') . "\" (category $setCategoryId)");

// ---------------------------------------------------------------------------
// the catalogue identity, from TCGplayer via tcgcsv
// ---------------------------------------------------------------------------
function fetchJson(string $url): ?array
{
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $body = @file_get_contents($url, false, stream_context_create(
            ['http' => ['timeout' => 30, 'user_agent' => 'DoubleSleeve/1.0']]
        ));
        if ($body !== false && is_array($decoded = json_decode($body, true))) {
            return $decoded;
        }
        sleep($attempt);
    }

    return null;
}

$products = fetchJson("https://tcgcsv.com/tcgplayer/$tcgCategory/$groupId/products");
$prices = fetchJson("https://tcgcsv.com/tcgplayer/$tcgCategory/$groupId/prices");
if ($products === null || $prices === null) {
    fail('tcgcsv unreachable');
}

$priceBySubtype = [];
foreach ($prices['results'] ?? [] as $row) {
    if (!empty($row['marketPrice'])) {
        $priceBySubtype[(int) $row['productId']][(string) $row['subTypeName']] = (float) $row['marketPrice'];
    }
}

$card = null;
foreach ($products['results'] ?? [] as $candidate) {
    $ext = [];
    foreach ($candidate['extendedData'] ?? [] as $entry) {
        $ext[$entry['name']] = $entry['value'];
    }
    if (!isset($ext['Number'])) {
        continue;   // sealed product lives in the same group
    }
    // Accept "SWSH075", "075/172" and the bare "75" a label might carry.
    $normalise = static fn (string $n): string => ltrim(strtolower(preg_replace('/\s+/', '', $n)), '0');
    if ($normalise((string) $ext['Number']) === $normalise($number)
        || strcasecmp((string) $ext['Number'], $number) === 0) {
        $card = $candidate + ['ext' => $ext];
        break;
    }
}
if ($card === null) {
    fail("no card numbered \"$number\" in group $groupId");
}

$tcgProductId = (int) $card['productId'];
$cardNumber = (string) $card['ext']['Number'];
$cardName = trim((string) preg_replace('/\s*-\s*' . preg_quote($cardNumber, '/') . '\s*$/', '', (string) $card['name']));
$rarity = (string) ($card['ext']['Rarity'] ?? '');
$rarity = $rarity === 'None' ? '' : $rarity;

$subtypes = $priceBySubtype[$tcgProductId] ?? [];
if ($subtypes === []) {
    fail("TCGplayer carries no market price for $cardName $cardNumber - price it by hand or wait for the market");
}
$printing = (string) ($options['printing'] ?? array_key_first($subtypes));
if (!isset($subtypes[$printing])) {
    fail("no market price for printing \"$printing\" - available: " . implode(', ', array_keys($subtypes)));
}
$marketUsd = $subtypes[$printing];

$usdCad = (float) $db->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "USDCAD"');
if ($usdCad < 1.0) {
    fail('no USDCAD rate in price_fx - run: make currency-sync');
}
$baseCad = round($marketUsd * $usdCad, 2);
line(sprintf('%s %s (%s, %s) - market $%.2f USD = $%.2f CAD', $cardName, $cardNumber, $printing, $rarity ?: 'no rarity', $marketUsd, $baseCad));

// ---------------------------------------------------------------------------
// create the product - or add the copy to an existing listing
// ---------------------------------------------------------------------------
$language = (string) ($options['language'] ?? ($region === 'Japanese' ? 'Japanese' : 'English'));
$reference = ($region === 'Japanese' ? 'PKMJP-' . $groupId . '-' : 'PKM-G' . $groupId . '-')
    . preg_replace('/[^A-Za-z0-9]/', '', $cardNumber);

$attributeId = static function (string $group, string $value) use ($db): ?int {
    $id = (int) $db->getValue(
        'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
           JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = 1
          WHERE agl.name = "' . pSQL($group) . '" AND al.name = "' . pSQL($value) . '"'
    );

    return $id ?: null;
};

$existingId = (int) $db->getValue(
    'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"'
);

if ($existingId) {
    /**
     * The card is already listed: this run adds STOCK, not a product. The same
     * (condition, language, printing) SKU gets its quantity bumped; a new state
     * gets a new combination priced off the base by the standard ladder.
     */
    // Matched by ATTRIBUTE SET, not by reference string: two eras of seeding
    // used two suffix conventions, and a reference miss would mint a duplicate
    // SKU with identical attributes - same copy, two rows, split stock.
    $wanted = array_values(array_filter([
        $attributeId('Condition', $condition), $attributeId('Card Language', $language),
        $attributeId('Printing', $printing), $attributeId('Grading', 'Ungraded'),
    ]));
    $comboRef = $reference . '-' . strtoupper(substr($language, 0, 2)) . '-'
        . preg_replace('/[^A-Z]/', '', ucwords($condition));
    $comboId = (int) $db->getValue(
        'SELECT pa.id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute pa
           JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
                AND pac.id_attribute IN (' . implode(',', $wanted) . ')
          WHERE pa.id_product = ' . $existingId . '
          GROUP BY pa.id_product_attribute
         HAVING COUNT(DISTINCT pac.id_attribute) = ' . count($wanted)
    );
    if ($comboId) {
        $sa = (int) $db->getValue(
            'SELECT quantity FROM ' . _DB_PREFIX_ . 'stock_available
              WHERE id_product_attribute = ' . $comboId
        );
        StockAvailable::setQuantity($existingId, $comboId, $sa + $qty);
        line("existing SKU $comboRef: quantity " . $sa . ' -> ' . ($sa + $qty));
    } else {
        $base = (float) $db->getValue('SELECT price FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . $existingId);
        $combination = new Combination();
        $combination->id_product = $existingId;
        $combination->price = round($base * CONDITION_LADDER[$condition] - $base, 2);
        $combination->reference = $comboRef;
        $combination->minimal_quantity = 1;
        $combination->add();
        $combination->setAttributes(array_values(array_filter([
            $attributeId('Condition', $condition), $attributeId('Card Language', $language),
            $attributeId('Printing', $printing), $attributeId('Grading', 'Ungraded'),
        ])));
        StockAvailable::setQuantity($existingId, (int) $combination->id, $qty);
        line("new SKU $comboRef at " . $condition);
    }
    Product::flushPriceCache();
    Tools::clearAllCache();
    line("done - product $existingId");
    exit(0);
}

$vocabulary = nameVocabulary('/provisioning/data/pokemon-species.csv', 'name_fr');
$speciesEn = loadSpeciesNames('/provisioning/data/pokemon-species.csv', 'name');
$frName = localiseCardName($cardName, $vocabulary);

$product = new Product();
$product->reference = $reference;
$product->price = $baseCad;
$product->id_category_default = $setCategoryId;
$product->active = true;
$product->visibility = 'both';
$product->id_tax_rules_group = 0;
$product->minimal_quantity = 1;
$product->out_of_stock = 0;

foreach (Language::getLanguages(false) as $lang) {
    $idLang = (int) $lang['id_lang'];
    $isEn = $lang['iso_code'] === 'en';
    $name = $isEn ? $cardName : $frName;
    $title = composeCardTitle($name, $setNames[$idLang] ?? ($setNames[1] ?? ''), $cardNumber);
    $product->name[$idLang] = $title;
    $product->link_rewrite[$idLang] = Tools::str2url($title) ?: 'card-' . strtolower($reference);
    $product->description[$idLang] = $isEn
        ? '<p>' . htmlspecialchars($name) . ' from ' . htmlspecialchars($setNames[$idLang] ?? '') . ', card '
          . htmlspecialchars($cardNumber) . '. Graded to TCGplayer condition standards and shipped in a sleeve '
          . 'and toploader.</p>'
        : '<p>' . htmlspecialchars($name) . ' de l\'extension ' . htmlspecialchars($setNames[$idLang] ?? '')
          . ', carte ' . htmlspecialchars($cardNumber) . '. Évaluée selon les normes d\'état de TCGplayer et '
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
    fail('product creation failed');
}
$productId = (int) $product->id;

// The full ancestor chain: PrestaShop parents list only DIRECTLY associated
// products, so the set alone would leave this card invisible on the era, region,
// Singles and Pokémon pages.
$chain = [$setCategoryId];
$walk = $setCategoryId;
for ($i = 0; $i < 4; ++$i) {
    $walk = (int) $db->getValue('SELECT id_parent FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . $walk);
    if ($walk <= (int) Configuration::get('PS_HOME_CATEGORY')) {
        break;
    }
    $chain[] = $walk;
}
$product->addToCategories($chain);

// features - the same facts the repair pass derives, written for this card only
$featureValue = static function (string $feature, string $value) use ($db): void {
    if (trim($value) === '') {
        return;
    }
    $featureId = (int) $db->getValue(
        'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
           JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
          WHERE fl.name = "' . pSQL($feature) . '"'
    );
    if (!$featureId) {
        return;
    }
    $valueId = (int) $db->getValue(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang l ON l.id_feature_value = fv.id_feature_value AND l.id_lang = 1
          WHERE fv.id_feature = ' . $featureId . ' AND l.value = "' . pSQL($value) . '"'
    );
    if (!$valueId) {
        $fv = new FeatureValue();
        $fv->id_feature = $featureId;
        $fv->custom = false;
        foreach (Language::getLanguages(false) as $lang) {
            $fv->value[(int) $lang['id_lang']] = $value;
        }
        $fv->add();
        $valueId = (int) $fv->id;
    }
    Db::getInstance()->execute(
        'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'feature_product (id_feature, id_product, id_feature_value)
         VALUES (' . $featureId . ', ' . $GLOBALS['productId'] . ', ' . $valueId . ')'
    );
};
$GLOBALS['productId'] = $productId;
$featureValue('Pokemon', (string) matchSpecies($cardName, $speciesEn));
$featureValue('Rarity', $rarity);
$featureValue('Card Number', $cardNumber);
$featureValue('Region', $region);
$featureValue('Set', $setNames[1] ?? '');

// identity - what titles, cutouts and the copies module key on
$db->execute(
    'INSERT INTO ' . _DB_PREFIX_ . 'card_identity (id_product, number, id_category_set, card_language, language_code, date_upd)
     VALUES (' . $productId . ', "' . pSQL($cardNumber) . '", ' . $setCategoryId . ',
             "' . pSQL($language) . '", "' . pSQL(strtoupper(substr($language, 0, 2))) . '", NOW())'
);
foreach (Language::getLanguages(false) as $lang) {
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'card_identity_lang (id_product, id_lang, card_name)
         VALUES (' . $productId . ', ' . (int) $lang['id_lang'] . ',
                 "' . pSQL($lang['iso_code'] === 'en' ? $cardName : $frName) . '")'
    );
}

// price engine wiring - from here on the 12-hourly sync owns this price
$db->execute(
    'INSERT INTO ' . _DB_PREFIX_ . 'price_source_map
        (reference, kind, tcgplayer_product_id, tcgplayer_group_id, tcgplayer_subtype)
     VALUES ("' . pSQL($reference) . '", "single", ' . $tcgProductId . ', ' . $groupId . ',
             "' . pSQL($printing) . '")'
);

// the SKU
$combination = new Combination();
$combination->id_product = $productId;
$combination->price = round($baseCad * CONDITION_LADDER[$condition] - $baseCad, 2);
$combination->reference = $reference . '-' . strtoupper(substr($language, 0, 2)) . '-'
    . preg_replace('/[^A-Z]/', '', ucwords($condition));
$combination->default_on = 1;
$combination->minimal_quantity = 1;
$combination->add();
$combination->setAttributes(array_values(array_filter([
    $attributeId('Condition', $condition), $attributeId('Card Language', $language),
    $attributeId('Printing', $printing), $attributeId('Grading', 'Ungraded'),
])));
StockAvailable::setQuantity($productId, (int) $combination->id, $qty);

/**
 * Attach a prepared GD image as a product image, cut out and at every size.
 *
 * Shared by the front scan and the back so the two get identical treatment -
 * the back went through ImageManager once and came out on an opaque canvas with
 * its rounded corners filled back in, beside a front that had been cut out.
 */
$attachCut = static function (int $productId, \GdImage $cut, bool $cover) use ($db): void {
    $image = new Image();
    $image->id_product = $productId;
    $image->position = Image::getHighestPosition($productId) + 1;
    $image->cover = $cover;
    if (!$image->add()) {
        return;
    }
    $image->associateTo((int) Context::getContext()->shop->id);
    $base = $image->getPathForCreation();
    cutoutSave($cut, $base . '.jpg');
    foreach (ImageType::getImagesTypes('products') as $type) {
        $thumb = cutoutResize($cut, (int) $type['width'], (int) $type['height']);
        cutoutSave($thumb, $base . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
};

// the scan: fetched, corner-cut, every size - for THIS card only
$imageUrl = str_replace('_200w', '_in_1000x1000', (string) $card['imageUrl']);
$bytes = @file_get_contents($imageUrl, false, stream_context_create(
    ['http' => ['timeout' => 30, 'user_agent' => 'DoubleSleeve/1.0']]
));
if ($bytes !== false && strlen($bytes) > 1024) {
    $tmp = tempnam(sys_get_temp_dir(), 'cc') . '.jpg';
    file_put_contents($tmp, $bytes);
    $loaded = cutoutLoad($tmp);
    $cut = $loaded !== null ? cutoutCard($loaded) : null;
    if ($cut !== null) {
        $attachCut($productId, $cut, true);
        imagedestroy($cut);
        line('scan attached and cut out');
    }
    if ($loaded !== null) {
        imagedestroy($loaded);
    }
    @unlink($tmp);
} else {
    line('no scan on TCGplayer CDN - listing goes up without one');
}

/**
 * The back, as a second photo.
 *
 * A buyer of a single expects both faces, and for Japanese cards the back is
 * identifying information rather than decoration: Japan changed its back in
 * 2002, so the set's release date decides which one is correct here. See
 * lib/cardback.php.
 */
$publishedOn = (string) $db->getValue(
    'SELECT published_on FROM ' . _DB_PREFIX_ . 'tcg_group_category WHERE group_id = ' . $groupId
);
$backPath = cardBackPath($region, $publishedOn);
if ($backPath !== null) {
    $backLoaded = cutoutLoad($backPath);
    $backCut = $backLoaded !== null ? cutoutCard($backLoaded) : null;
    if ($backCut !== null) {
        $attachCut($productId, $backCut, false);
        imagedestroy($backCut);
        line('back attached: ' . cardBackLabel($region, $publishedOn));
    }
    if ($backLoaded !== null) {
        imagedestroy($backLoaded);
    }
} else {
    line("no back scan known for the $region region");
}

Product::flushPriceCache();
Tools::clearAllCache();

line(sprintf('done - product %d "%s" listed at $%.2f CAD (%s, qty %d)',
    $productId, $cardName . ' - ' . ($setNames[1] ?? '') . ' ' . $cardNumber, $baseCad, $condition, $qty));
