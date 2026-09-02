<?php
/**
 * Retires the "Print Region" feature.
 *
 * It sat in the Sealed filter rail directly beneath "Region", which is the
 * catalogue axis every product carries (Western / Japanese / Chinese, derived
 * from the set). Two facets one row apart, near-identical names, answering what
 * a shopper reads as the same question.
 *
 * The concept it gestured at is real: an Asia-English booster box and a US box
 * of the same set are different markets at different prices. The DATA was not.
 * Every one of its 54 rows carried the same literal, "US / International
 * English", hardcoded in the seeder rather than derived from anything - and the
 * Japanese sealed stock carried no value at all. A facet whose every product
 * shares one value offers no choice; a fact nothing derives is not a fact.
 *
 * Deleted rather than left unpopulated: an empty feature still lists in the
 * admin, the exports and the facet templates, and invites someone to "fill it
 * in" by hand. If Asia-English sealed stock ever arrives, this comes back with
 * a source behind it.
 *
 * NOT to be confused with "Print Run" (Shadowless, 1st Edition), which is a
 * genuinely different axis and stays.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/migrations/retire-print-region.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }

echo "\n\033[1m== Retiring the Print Region feature\033[0m\n";

$db = Db::getInstance();

$featureId = (int) $db->getValue(
    'SELECT f.id_feature FROM ' . _DB_PREFIX_ . 'feature f
       JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = 1
      WHERE fl.name = "Print Region"'
);
if (!$featureId) {
    line('already retired');

    return;
}

$attached = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_product WHERE id_feature = ' . $featureId
);

// Feature::delete() takes its values and product rows with it.
$feature = new Feature($featureId);
if (Validate::isLoadedObject($feature)) {
    $feature->delete();
    line("removed, freeing $attached product rows");
}

Tools::clearAllCache();
