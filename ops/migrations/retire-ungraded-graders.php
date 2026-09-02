<?php
/**
 * Retires the grading companies the shop does not carry.
 *
 * The Grading axis shipped with six companies because that is who grades cards.
 * The shop stocks four - PSA, BGS, CGC and TAG - and that is not a preference:
 * a graded SKU's photograph is the card composited into ITS grader's holder, and
 * `ops/assets/slab-templates` holds frames for those four and nobody else. A
 * company in the vocabulary with no frame behind it is a filter a shopper can
 * select to be shown nothing, and an intake path that would produce a slab
 * photographed in the wrong holder.
 *
 * Refuses to remove anything that is in use, so it cannot quietly delete stock:
 * if a company turns out to have combinations, it is reported and left alone.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/migrations/retire-ungraded-graders.php
 *   ... --dry
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

/** Everything not on this list is retired. Keep it in step with slab-templates. */
const CARRIED = ['PSA', 'BGS', 'CGC', 'TAG', 'Ungraded'];

$dry = in_array('--dry', $argv ?? [], true);

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Retiring graders the shop does not carry\033[0m\n";
if ($dry) {
    line('DRY RUN - nothing is written');
}

$db = Db::getInstance();

$groupId = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
      WHERE id_lang = 1 AND name = "Grading"'
);
if (!$groupId) {
    warn('no Grading attribute group - nothing to do');
    exit(0);
}

$removed = 0;
foreach ($db->executeS(
    'SELECT a.id_attribute, al.name
       FROM ' . _DB_PREFIX_ . 'attribute a
       JOIN ' . _DB_PREFIX_ . 'attribute_lang al
            ON al.id_attribute = a.id_attribute AND al.id_lang = 1
      WHERE a.id_attribute_group = ' . $groupId
) ?: [] as $row) {
    $name = (string) $row['name'];
    if (in_array($name, CARRIED, true)) {
        continue;
    }

    $inUse = (int) $db->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute_combination
          WHERE id_attribute = ' . (int) $row['id_attribute']
    );
    if ($inUse > 0) {
        // Stock beats policy: something is on the shelf in this holder, and
        // deleting the axis value would orphan it.
        warn(sprintf('%s is on %d combination(s) - left alone', $name, $inUse));
        continue;
    }

    line($name . ' retired');
    if (!$dry) {
        /**
         * Deleted in SQL rather than through the ObjectModel: PrestaShop 9 has
         * no `Attribute::delete()` to call, and the rows an attribute owns are
         * exactly these two plus the layered-search index, which `facets.php`
         * rebuilds from scratch on its next run anyway.
         */
        $id = (int) $row['id_attribute'];
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'attribute_lang WHERE id_attribute = ' . $id);
        $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'attribute WHERE id_attribute = ' . $id);
    }
    ++$removed;
}

line($dry ? 'dry run complete' : $removed . ' retired');
if (!$dry && $removed) {
    line('run `make facets` so the Grading rail drops them too');
}
