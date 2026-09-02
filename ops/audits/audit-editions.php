<?php
/**
 * Audits the 1st Edition / Unlimited distinction across the whole catalogue.
 *
 * Like shadowed/shadowless, this is a print-run distinction on identical cards at
 * identical collector numbers, and the price gap is large - a 1st Edition Jungle
 * holo is worth several times its Unlimited twin. Unlike shadowed/shadowless,
 * TCGplayer models it as SKU subtypes inside one group rather than as parallel
 * groups, so it is invisible at set level and easy to leave unlabelled.
 *
 * This reports, for every group:
 *   - which edition subtypes TCGplayer actually lists
 *   - whether the group is "edition-split" (both 1st Edition and Unlimited exist)
 *   - for sets we stock, whether every SKU carries an unambiguous edition
 *
 * The failure it is looking for is a SKU in an edition-split set whose printing
 * says only "Holofoil" or "Normal" - that tells a buyer nothing about which run
 * they are getting, in exactly the sets where it matters most.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/audits/audit-editions.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const TCGCSV_BASE = 'https://tcgcsv.com/tcgplayer/3/';
const CACHE_FILE = '/tmp/tcg-subtypes.json';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Edition audit: 1st Edition vs Unlimited\033[0m\n";

$db = Db::getInstance();
$lang = (int) Configuration::get('PS_LANG_DEFAULT');

/** subTypeName => count for a group, cached to disk so reruns are cheap. */
function subtypes(int $groupId, array &$cache): array
{
    if (isset($cache[(string) $groupId])) {
        return $cache[(string) $groupId];
    }
    $context = stream_context_create(['http' => [
        'timeout' => 45,
        'user_agent' => 'DoubleSleeve/1.0 (catalogue audit)',
    ]]);
    $body = @file_get_contents(TCGCSV_BASE . $groupId . '/prices', false, $context);
    if ($body === false) {
        return $cache[(string) $groupId] = [];
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
    usleep(120000);

    return $cache[(string) $groupId] = $counts;
}

$cache = is_readable(CACHE_FILE)
    ? (json_decode((string) file_get_contents(CACHE_FILE), true) ?: [])
    : [];

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$groups = [];
while (($row = fgetcsv($handle)) !== false) {
    $g = array_combine($header, array_pad($row, count($header), ''));
    $groups[] = $g;
}
fclose($handle);

line(count($groups) . ' TCGplayer groups to scan');

$split = [];
$scanned = 0;
foreach ($groups as $g) {
    $subs = subtypes((int) $g['group_id'], $cache);
    ++$scanned;
    if ($scanned % 40 === 0) {
        echo "     ... $scanned scanned\n";
        file_put_contents(CACHE_FILE, json_encode($cache));
    }
    if ($subs === []) {
        continue;
    }

    $hasFirst = false;
    $hasUnlimited = false;
    foreach (array_keys($subs) as $name) {
        if (stripos($name, '1st Edition') !== false) { $hasFirst = true; }
        if (stripos($name, 'Unlimited') !== false) { $hasUnlimited = true; }
    }
    if ($hasFirst || $hasUnlimited) {
        $split[(string) $g['name']] = [
            'group_id' => (int) $g['group_id'],
            'subs' => $subs,
            'both' => $hasFirst && $hasUnlimited,
        ];
    }
}
file_put_contents(CACHE_FILE, json_encode($cache));

echo "\n";
line(count($split) . ' groups carry an edition subtype:');
foreach ($split as $name => $info) {
    printf("   %-6s %-42s %s\n",
        $info['both'] ? 'BOTH' : 'one',
        substr($name, 0, 42),
        implode(', ', array_keys($info['subs']))
    );
}

// ---------------------------------------------------------------------------
// Do the sets we stock label every SKU unambiguously?
// ---------------------------------------------------------------------------
echo "\n";
line('checking stocked sets in edition-split groups');

$problems = 0;
foreach ($split as $name => $info) {
    if (!$info['both']) {
        continue;
    }
    $categoryId = (int) $db->getValue(
        'SELECT id_category FROM ' . _DB_PREFIX_ . 'tcg_group_category
          WHERE group_id = ' . $info['group_id']
    );
    if (!$categoryId) {
        continue;
    }

    $rows = $db->executeS(
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
    ) ?: [];

    if ($rows === []) {
        continue; // not stocked
    }

    $ambiguous = [];
    foreach ($rows as $row) {
        $printing = (string) $row['name'];
        if (stripos($printing, '1st Edition') === false && stripos($printing, 'Unlimited') === false) {
            $ambiguous[] = $printing . ' (' . (int) $row['n'] . ' SKUs)';
        }
    }

    if ($ambiguous === []) {
        printf("   OK   %-30s every SKU states its edition\n", substr($name, 0, 30));
    } else {
        ++$problems;
        printf("   FAIL %-30s ambiguous: %s\n", substr($name, 0, 30), implode(', ', $ambiguous));
    }
}

echo "\n";
if ($problems === 0) {
    line('no ambiguous SKUs in edition-split sets');
} else {
    warn("$problems stocked set(s) have SKUs that do not state their edition");
}

// ---------------------------------------------------------------------------
// parallel-group editions: the case TCGplayer's subtypes cannot express
// ---------------------------------------------------------------------------
/**
 * A set whose 1st Edition run lives in a DIFFERENT group.
 *
 * Base Set is the only one: its 1st Edition is shadowless, so TCGplayer files it
 * in "Base Set (Shadowless)" and leaves plain "Base Set" holding the shadowed
 * Unlimited run alone - named bare "Holofoil"/"Normal", because inside that group
 * there is nothing to disambiguate against. The check above cannot see this, as
 * the group carries no edition subtype to notice the absence of.
 *
 * The pairing is found from the category names rather than hardcoded, so a second
 * shadowless set would be picked up without touching this file.
 */
echo "\n";
line('checking sets whose 1st Edition lives in a parallel group');

$db = Db::getInstance();
$shadowPairs = $db->executeS(
    'SELECT shadowless.id_category AS id_shadowless, shadowless.name AS name_shadowless,
            shadowed.id_category AS id_shadowed, shadowed.name AS name_shadowed
       FROM ' . _DB_PREFIX_ . 'category_lang shadowless
       JOIN ' . _DB_PREFIX_ . 'category_lang shadowed
            ON shadowed.id_lang = 1
            AND shadowed.name = TRIM(REPLACE(shadowless.name, "(Shadowless)", ""))
      WHERE shadowless.id_lang = 1 AND shadowless.name LIKE "%(Shadowless)"'
) ?: [];

$parallelProblems = 0;
foreach ($shadowPairs as $pair) {
    foreach ([['shadowed', (int) $pair['id_shadowed'], (string) $pair['name_shadowed']],
              ['shadowless', (int) $pair['id_shadowless'], (string) $pair['name_shadowless']]] as [$side, $categoryId, $label]) {
        // Every SKU must state an edition, on BOTH sides of the pair.
        $bare = $db->executeS(
            'SELECT al.name, COUNT(*) n
               FROM ' . _DB_PREFIX_ . 'card_identity ci
               JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.id_product = ci.id_product
               JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                    ON pac.id_product_attribute = pa.id_product_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                    ON agl.id_attribute_group = a.id_attribute_group
                    AND agl.id_lang = 1 AND agl.name = "Printing"
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = 1
              WHERE ci.id_category_set = ' . $categoryId . '
                AND al.name NOT LIKE "%1st Edition%"
                AND al.name NOT LIKE "%Unlimited%"
              GROUP BY al.name'
        ) ?: [];

        // And every PRODUCT must state which pressing it is, or the facet only
        // works in one direction.
        $unstamped = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_identity ci
              WHERE ci.id_category_set = ' . $categoryId . '
                AND NOT EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'feature_product fp
                                 JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                                      ON fl.id_feature = fp.id_feature AND fl.id_lang = 1
                                WHERE fp.id_product = ci.id_product AND fl.name = "Print Run")'
        );

        if ($bare === [] && $unstamped === 0) {
            printf("   OK   %-30s edition on every SKU, print run on every product\n", substr($label, 0, 30));
            continue;
        }
        ++$parallelProblems;
        $why = [];
        foreach ($bare as $row) {
            $why[] = 'bare "' . $row['name'] . '" x' . (int) $row['n'];
        }
        if ($unstamped) {
            $why[] = $unstamped . ' products with no Print Run';
        }
        printf("   FAIL %-30s %s\n", substr($label, 0, 30), implode(', ', $why));
    }
}

if ($shadowPairs === []) {
    line('no parallel shadowless/shadowed set pairs in the catalogue');
} elseif ($parallelProblems === 0) {
    line('parallel-group sets label both their edition and their print run');
} else {
    warn("$parallelProblems side(s) of a shadowless/shadowed pair are incompletely labelled");
    warn('fix with: make base-set-unlimited');
}
