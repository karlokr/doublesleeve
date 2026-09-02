<?php
/**
 * Makes our set names agree with Collectr's.
 *
 * Collectr is the reference a Pokémon collector already has open in another tab,
 * so a set called one thing here and another there is a set they cannot find.
 * Their catalogue is keyed on the SAME TCGplayer group ids ours is, so the join
 * is exact for English and needs no name matching at all. (Japanese is not: see
 * the note in docs/tasks.md.)
 *
 * Names only, from `data/collectr-sets-en.tsv`, and only the English one - the
 * French name is translated separately and is not Collectr's to set. Set
 * ARTWORK keeps coming from where it always has (pokemontcg.io, the Bulbagarden
 * Archives, and lib/logo.php's name matching); this script does not touch it.
 *
 * The link_rewrite is left alone deliberately: category URLs here are id-first
 * (`/604-base-set`), so a renamed set keeps resolving on its old slug and no
 * inbound link breaks.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/catalog/align-collectr.php
 *   ... --dry   report what would change and write nothing
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const SETS_TSV = '/provisioning/data/collectr-sets-en.tsv';

/**
 * Two sets keep our names, for now, because CODE reads them.
 *
 * The shadowed/shadowless split is derived from the category NAME: a set is
 * shadowless if its name ends "(Shadowless)", and shadowed if a sibling exists
 * called "<name> (Shadowless)" (see cryptocards_theme.php, printRunSets). Taking
 * Collectr's names here - "Base Set (Unlimited)" and "Base Set (1st Edition &
 * Shadowless)" - satisfies neither rule, so the Not Shadowless chip and the
 * print-run facet would both go quiet with nothing to show it had happened.
 *
 * Collectr's names are the better ones and this is the wrong reason to refuse
 * them. The fix is to key that detection on the group id, the way the printing
 * renames in lib/printing.php already are, and then delete this list - see the
 * task in docs/tasks.md.
 */
const HOLD = [
    604 => 'shadowed/shadowless detection reads this name',
    1663 => 'shadowed/shadowless detection reads this name',
];

$dry = in_array('--dry', $argv ?? [], true);

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Aligning sets with Collectr\033[0m\n";
if ($dry) {
    line('DRY RUN - nothing is written');
}

$rows = @file(SETS_TSV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($rows === false) {
    warn('cannot read ' . SETS_TSV);
    exit(1);
}

$db = Db::getInstance();

/** group id => the category that group built, for the groups we actually hold. */
$categoryOf = [];
foreach ($db->executeS(
    'SELECT group_id, id_category FROM ' . _DB_PREFIX_ . 'tcg_group_category'
) ?: [] as $row) {
    $categoryOf[(int) $row['group_id']] = (int) $row['id_category'];
}

$renamed = 0;
$agreed = 0;
$held = 0;
$absent = 0;

foreach ($rows as $row) {
    $parts = explode("\t", $row);
    if (count($parts) < 2) {
        continue;
    }
    $groupId = (int) $parts[0];
    $name = trim($parts[1]);
    if ($groupId <= 0 || $name === '') {
        continue;
    }

    $categoryId = $categoryOf[$groupId] ?? null;
    if ($categoryId === null) {
        // Collectr carries a set we do not. Reported rather than created: a
        // category with no products is a dead end in the tree, and stocking it
        // is an inventory decision, not a naming one.
        ++$absent;
        continue;
    }

    $current = (string) $db->getValue(
        'SELECT name FROM ' . _DB_PREFIX_ . 'category_lang
          WHERE id_category = ' . $categoryId . ' AND id_lang = 1'
    );

    if ($current === $name) {
        ++$agreed;
    } elseif (isset(HOLD[$groupId])) {
        warn(sprintf('%-7d kept as "%s" - %s', $groupId, $current, HOLD[$groupId]));
        ++$held;
    } else {
        line(sprintf('%-7d %-42s -> %s', $groupId, $current, $name));
        if (!$dry) {
            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'category_lang
                    SET name = "' . pSQL($name) . '"
                  WHERE id_category = ' . $categoryId . ' AND id_lang = 1'
            );
        }
        ++$renamed;
    }
}

line($agreed . ' set(s) already agreed');
line($renamed . ' renamed');
if ($held) {
    line($held . ' held back - see the HOLD list above');
}
if ($absent) {
    line($absent . ' Collectr set(s) we do not carry - left alone');
}
