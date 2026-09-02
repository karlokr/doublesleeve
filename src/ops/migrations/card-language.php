<?php
/**
 * Card language is a variant axis, not a separate product.
 *
 * A Western set is ONE release printed in several languages at the same collector
 * numbers, so an English and a French Charizard are the same card in the same set.
 * They belong on one listing with a language selector, which is also how Cardmarket
 * models the market this shop sells into.
 *
 * This reverses an earlier decision to split them into separate products. That was
 * argued on two grounds, both of which are simply false:
 *
 *   "each language needs its own photo"  - PrestaShop stores images per COMBINATION
 *                                          (product_attribute_image), so a French
 *                                          SKU carries its own scan.
 *   "each language has its own price"    - combinations carry a price impact.
 *
 * Neither forces a product split. What the split DID force was a language tag baked
 * into product_lang.name, which then cannot be right for a product holding two
 * languages.
 *
 * Idempotent, and safe: every combination gets exactly one language attribute, so
 * no SKU changes shape beyond gaining it.
 *
 *   make card-language
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Card language: product feature -> SKU axis\033[0m\n";

$db = Db::getInstance();

// ---------------------------------------------------------------------------
// the attribute group must exist - setup.php owns its definition and labels
// ---------------------------------------------------------------------------
$groupId = (int) $db->getValue(
    'SELECT ag.id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group ag
       JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
            ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = 1
      WHERE agl.name = "Card Language"'
);
if (!$groupId) {
    warn('Card Language attribute group not found - run setup.php first');
    exit(1);
}
line("attribute group id: $groupId");

$attributes = [];
foreach ($db->executeS(
    'SELECT a.id_attribute, al.name
       FROM ' . _DB_PREFIX_ . 'attribute a
       JOIN ' . _DB_PREFIX_ . 'attribute_lang al
            ON al.id_attribute = a.id_attribute AND al.id_lang = 1
      WHERE a.id_attribute_group = ' . $groupId
) ?: [] as $row) {
    $attributes[(string) $row['name']] = (int) $row['id_attribute'];
}
line(count($attributes) . ' language values available');

// ---------------------------------------------------------------------------
// what the product-level feature says, so nothing is guessed
// ---------------------------------------------------------------------------
$featureId = (int) $db->getValue(
    'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
       JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
      WHERE fl.name = "Card Language"'
);

$languageByProduct = [];
if ($featureId) {
    foreach ($db->executeS(
        'SELECT fp.id_product, fvl.value
           FROM ' . _DB_PREFIX_ . 'feature_product fp
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = 1
          WHERE fp.id_feature = ' . $featureId
    ) ?: [] as $row) {
        $languageByProduct[(int) $row['id_product']] = (string) $row['value'];
    }
}
line(count($languageByProduct) . ' products carry a language on the old feature');

// ---------------------------------------------------------------------------
// attach it to every combination
// ---------------------------------------------------------------------------
/**
 * Only combinations of products that ARE cards. Sealed product has no card
 * language - a booster box is a sealed box, whatever is printed inside it.
 */
$rows = $db->executeS(
    'SELECT pa.id_product_attribute, pa.id_product
       FROM ' . _DB_PREFIX_ . 'product_attribute pa
       JOIN ' . _DB_PREFIX_ . 'card_identity ci ON ci.id_product = pa.id_product'
) ?: [];

$attached = 0;
$already = 0;
$unknown = [];

foreach ($rows as $row) {
    $skuId = (int) $row['id_product_attribute'];
    $productId = (int) $row['id_product'];

    $existing = (int) $db->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
           JOIN ' . _DB_PREFIX_ . 'attribute a
                ON a.id_attribute = pac.id_attribute AND a.id_attribute_group = ' . $groupId . '
          WHERE pac.id_product_attribute = ' . $skuId
    );
    if ($existing > 0) {
        ++$already;
        continue;
    }

    $language = $languageByProduct[$productId] ?? 'English';
    if (!isset($attributes[$language])) {
        $unknown[$language] = true;
        continue;
    }

    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'product_attribute_combination (id_attribute, id_product_attribute)
         VALUES (' . $attributes[$language] . ', ' . $skuId . ')'
    );
    ++$attached;
}

line("combinations given a language: $attached (already had one: $already)");
if ($unknown !== []) {
    warn('no attribute value for: ' . implode(', ', array_keys($unknown)));
}

// ---------------------------------------------------------------------------
// retire the product-level feature
// ---------------------------------------------------------------------------
/**
 * Removed rather than left in place. Two sources for one fact is how they drift,
 * and a stale "Card Language" feature would keep filtering products by a language
 * their SKUs no longer agree with.
 */
if ($featureId) {
    $feature = new Feature($featureId);
    if (Validate::isLoadedObject($feature)) {
        $feature->delete();
        line('product feature "Card Language" removed');
    }
}

Product::flushPriceCache();
Tools::clearAllCache();
