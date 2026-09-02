<?php
/**
 * Renames shadowed Base Set printings to the Unlimited run they actually are.
 *
 * TCGplayer names group 604's SKUs bare "Holofoil" and "Normal" because that
 * group holds only the shadowed Unlimited run — the 1st Edition and shadowless
 * printings live in group 1663. Correct there, misleading here, where both sets
 * list side by side: a tile reading "Holofoil" beside one reading "1st Edition
 * Holofoil" reads as the earlier printing when it is the later and far cheaper
 * one. See lib/printing.php for the rule; this applies it to stock that already
 * exists.
 *
 * Re-points combinations at attribute values that ALREADY exist (the shadowless
 * group uses them), so no vocabulary is created and catalog/align-tcgplayer.php
 * has nothing new to prune.
 *
 * Also stamps the PRODUCT's print run. The shadowless side was the only one ever
 * given a Print Run value, so the facet offered "Shadowless" and no way to ask
 * for the shadowed run — you could filter to the rarer pressing and not to the
 * commoner one sitting right beside it. The two facts are separate on purpose:
 * print run is a property of the product (which pressing this listing is),
 * edition is a property of the SKU (one shadowless product holds both a 1st
 * Edition and an Unlimited combination).
 *
 * Idempotent: a combination already on the target value is left alone.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/migrations/base-set-unlimited.php
 *   ... --dry-run   report what would change and write nothing
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/printing.php';

define('DRY_RUN', in_array('--dry-run', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Base Set printings -> Unlimited\033[0m\n";

$db = Db::getInstance();

$printingGroup = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
      WHERE id_lang = 1 AND name = "Printing"'
);
if (!$printingGroup) {
    warn('Printing attribute group missing - run `make align` first');
    exit(1);
}

/** Attribute id for a Printing value, by its English name. */
function printingAttribute(int $group, string $name): int
{
    return (int) Db::getInstance()->getValue(
        'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                ON al.id_attribute = a.id_attribute AND al.id_lang = 1
          WHERE a.id_attribute_group = ' . $group . ' AND al.name = "' . pSQL($name) . '"'
    );
}

$moved = 0;
$already = 0;

foreach (PRINTING_GROUP_OVERRIDES as $groupId => $renames) {
    /**
     * Products are found through the price source map, which records the
     * TCGplayer group each product came from. Matching on the reference prefix
     * would work today and break the moment a set's abbreviation changes.
     */
    $products = $db->executeS(
        'SELECT m.reference, p.id_product
           FROM ' . _DB_PREFIX_ . 'price_source_map m
           JOIN ' . _DB_PREFIX_ . 'product p ON p.reference = m.reference
          WHERE m.tcgplayer_group_id = ' . (int) $groupId
    ) ?: [];
    line(count($products) . ' products in TCGplayer group ' . (int) $groupId);

    foreach ($renames as $from => $to) {
        $fromId = printingAttribute($printingGroup, (string) $from);
        $toId = printingAttribute($printingGroup, (string) $to);
        if (!$fromId || !$toId) {
            warn("missing Printing value: $from or $to - skipped");
            continue;
        }

        foreach ($products as $product) {
            $combinations = $db->executeS(
                'SELECT pac.id_product_attribute
                   FROM ' . _DB_PREFIX_ . 'product_attribute pa
                   JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                        ON pac.id_product_attribute = pa.id_product_attribute
                  WHERE pa.id_product = ' . (int) $product['id_product'] . '
                    AND pac.id_attribute = ' . $fromId
            ) ?: [];

            foreach ($combinations as $combination) {
                $comboId = (int) $combination['id_product_attribute'];
                // Guard against a combination that somehow carries both values.
                if ((int) $db->getValue(
                    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute_combination
                      WHERE id_product_attribute = ' . $comboId . ' AND id_attribute = ' . $toId
                )) {
                    ++$already;
                    continue;
                }
                if (!DRY_RUN) {
                    $db->execute(
                        'UPDATE ' . _DB_PREFIX_ . 'product_attribute_combination
                            SET id_attribute = ' . $toId . '
                          WHERE id_product_attribute = ' . $comboId . '
                            AND id_attribute = ' . $fromId
                    );
                }
                ++$moved;
            }
        }
        line(sprintf('%-10s -> %-20s', $from, $to) . ($moved ? " ($moved combinations so far)" : ''));
    }
}

line(($moved ? ($moved . ' combinations re-pointed') : 'nothing to change')
    . ($already ? ", $already already correct" : '') . (DRY_RUN ? ' (dry run)' : ''));

// ---------------------------------------------------------------------------
// print run: stamp the shadowed side so the facet has both halves
// ---------------------------------------------------------------------------
$printRunFeature = (int) $db->getValue(
    'SELECT id_feature FROM ' . _DB_PREFIX_ . 'feature_lang WHERE id_lang = 1 AND name = "Print Run"'
);
$shadowedValue = (int) $db->getValue(
    'SELECT fvl.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value_lang fvl
       JOIN ' . _DB_PREFIX_ . 'feature_value fv ON fv.id_feature_value = fvl.id_feature_value
      WHERE fvl.id_lang = 1 AND fvl.value = "Shadowed" AND fv.id_feature = ' . $printRunFeature
);

$stamped = 0;
if (!$printRunFeature || !$shadowedValue) {
    warn('Print Run feature or its "Shadowed" value is missing - run `make provision` first');
} else {
    foreach (PRINTING_GROUP_OVERRIDES as $groupId => $ignored) {
        foreach ($db->executeS(
            'SELECT p.id_product FROM ' . _DB_PREFIX_ . 'price_source_map m
               JOIN ' . _DB_PREFIX_ . 'product p ON p.reference = m.reference
              WHERE m.tcgplayer_group_id = ' . (int) $groupId
        ) ?: [] as $product) {
            $productId = (int) $product['id_product'];
            if ((int) $db->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_product
                  WHERE id_product = ' . $productId . ' AND id_feature = ' . $printRunFeature
            )) {
                continue;
            }
            if (!DRY_RUN) {
                $db->execute(
                    'INSERT INTO ' . _DB_PREFIX_ . 'feature_product (id_feature, id_product, id_feature_value)
                     VALUES (' . $printRunFeature . ', ' . $productId . ', ' . $shadowedValue . ')'
                );
            }
            ++$stamped;
        }
    }
    line($stamped ? "$stamped products stamped Print Run = Shadowed" : 'print run already stamped');
}

// Flushed when EITHER pass changed something: a run that only stamped print runs
// still changes what the facet returns.
if (!DRY_RUN && ($moved > 0 || $stamped > 0)) {
    Product::flushPriceCache();
    Tools::clearAllCache();
}
