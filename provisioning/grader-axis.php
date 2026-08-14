<?php
/**
 * Every card combination carries a Grading attribute; existing stock is Ungraded.
 *
 * Companion to card-language.php and the same shape of migration: an axis that
 * used to be a separate product tree (Graded > PSA > ...) becomes an attribute on
 * the card's own SKUs. Every combination that predates the axis is a raw copy by
 * definition - graded stock only ever enters through seed-graded.php, which
 * writes its Grading state explicitly.
 *
 * Idempotent: a combination that already has any Grading attribute is left alone.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/grader-axis.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Grading: separate products -> SKU axis\033[0m\n";

$db = Db::getInstance();

$groupId = (int) $db->getValue(
    'SELECT ag.id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group ag
       JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
            ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = 1
      WHERE agl.name = "Grading"'
);
if (!$groupId) {
    warn('Grading attribute group not found - run setup.php first');
    exit(1);
}

$rawId = (int) $db->getValue(
    'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
       JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
      WHERE a.id_attribute_group = ' . $groupId . ' AND al.name = "Ungraded"'
);
if (!$rawId) {
    warn('Ungraded value missing from the Grading group');
    exit(1);
}

$rows = $db->executeS(
    'SELECT pa.id_product_attribute
       FROM ' . _DB_PREFIX_ . 'product_attribute pa
       JOIN ' . _DB_PREFIX_ . 'card_identity ci ON ci.id_product = pa.id_product
      WHERE NOT EXISTS (
            SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
              JOIN ' . _DB_PREFIX_ . 'attribute a
                   ON a.id_attribute = pac.id_attribute AND a.id_attribute_group = ' . $groupId . '
             WHERE pac.id_product_attribute = pa.id_product_attribute)'
) ?: [];

foreach ($rows as $row) {
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'product_attribute_combination (id_attribute, id_product_attribute)
         VALUES (' . $rawId . ', ' . (int) $row['id_product_attribute'] . ')'
    );
}
line(count($rows) . ' combinations marked Ungraded');

/**
 * The old model's product-level features go with it. Two sources for one fact
 * drift, and a "Grading Company" feature that no product carries any more would
 * still render as an empty facet template entry.
 */
foreach (['Grading Company', 'Grade'] as $legacy) {
    $featureId = (int) $db->getValue(
        'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
           JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
          WHERE fl.name = "' . pSQL($legacy) . '"'
    );
    if ($featureId) {
        $inUse = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_product WHERE id_feature = ' . $featureId
        );
        if ($inUse > 0) {
            warn("feature \"$legacy\" still on $inUse products - left in place");
            continue;
        }
        $feature = new Feature($featureId);
        if (Validate::isLoadedObject($feature)) {
            $feature->delete();
            line("retired feature: $legacy");
        }
    }
}

Product::flushPriceCache();
Tools::clearAllCache();
