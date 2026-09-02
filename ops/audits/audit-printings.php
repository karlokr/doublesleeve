<?php
/**
 * Audits our printing vocabulary against TCGplayer, set by set.
 *
 * TCGplayer models print runs in two different places and it is easy to get
 * backwards:
 *
 *   - Some distinctions are separate GROUPS. "Base Set" and "Base Set
 *     (Shadowless)" are parallel groups holding the same cards, because the
 *     shadowless run is a different print run of the same set.
 *   - Others are SKU subtypes inside one group. Jungle holds 1st Edition and
 *     Unlimited together; there was never a shadowless Jungle.
 *
 * Getting this wrong is expensive - a 1st Edition Base Set Charizard and an
 * Unlimited one differ by four figures - so this compares what we stock against
 * TCGplayer's own subTypeName list per group and reports any divergence.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/audits/audit-printings.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const TCGCSV_BASE = 'https://tcgcsv.com/tcgplayer/3/';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Printing audit: our catalogue vs TCGplayer\033[0m\n";

$db = Db::getInstance();
$lang = (int) Configuration::get('PS_LANG_DEFAULT');

/** TCGplayer's subtype names for a group, from the free daily mirror. */
function tcgSubtypes(int $groupId): ?array
{
    $context = stream_context_create(['http' => [
        'timeout' => 40,
        'user_agent' => 'DoubleSleeve/1.0 (catalogue audit)',
    ]]);
    $body = @file_get_contents(TCGCSV_BASE . $groupId . '/prices', false, $context);
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);
    $counts = [];
    foreach ($data['results'] ?? [] as $row) {
        $name = (string) ($row['subTypeName'] ?? '');
        if ($name !== '') {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    }
    ksort($counts);

    return $counts;
}

// Only sets we actually stock - the rest have no combinations to compare.
$sets = $db->executeS(
    'SELECT g.group_id, g.id_category, cl.name
       FROM ' . _DB_PREFIX_ . 'tcg_group_category g
       JOIN ' . _DB_PREFIX_ . 'category_lang cl
         ON cl.id_category = g.id_category AND cl.id_lang = ' . $lang . '
      WHERE EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'category_product cp
                     WHERE cp.id_category = g.id_category)
      ORDER BY cl.name'
) ?: [];

line(count($sets) . ' stocked sets to audit');

$problems = 0;

foreach ($sets as $set) {
    $categoryId = (int) $set['id_category'];
    $groupId = (int) $set['group_id'];

    $ours = [];
    foreach ($db->executeS(
        'SELECT al.name, COUNT(DISTINCT pa.id_product_attribute) n
           FROM ' . _DB_PREFIX_ . 'category_product cp
           JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.id_product = cp.id_product
           JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
           JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $lang . '
           JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $lang . '
          WHERE cp.id_category = ' . $categoryId . ' AND agl.name = "Printing"
          GROUP BY al.name'
    ) ?: [] as $row) {
        $ours[(string) $row['name']] = (int) $row['n'];
    }

    $theirs = tcgSubtypes($groupId);
    if ($theirs === null) {
        warn($set['name'] . ': TCGplayer fetch failed, skipped');
        continue;
    }

    // We stock a subset of any set, so "they have a printing we do not" is normal.
    // The failure that matters is the reverse: a printing we sell that TCGplayer
    // does not list for this group means the card is filed under the wrong set.
    $invented = array_diff(array_keys($ours), array_keys($theirs));
    $status = $invented === [] ? 'OK  ' : 'FAIL';
    if ($invented !== []) {
        ++$problems;
    }

    printf("   %s %-26s ours[%s] tcgplayer[%s]\n",
        $status,
        substr((string) $set['name'], 0, 26),
        implode(', ', array_keys($ours)),
        implode(', ', array_keys($theirs))
    );
    if ($invented !== []) {
        warn('      not a TCGplayer printing for this group: ' . implode(', ', $invented));
    }
}

echo "\n";
if ($problems === 0) {
    line('no invented printings - every printing we sell exists in its TCGplayer group');
} else {
    warn("$problems set(s) carry printings TCGplayer does not list for that group");
}
