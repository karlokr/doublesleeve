<?php
/**
 * Gives every graded copy in the shop a photo of the card inside its own slab.
 *
 * seed-graded.php photographs a slab as it creates it, but that only covers the
 * fixture stock it knows about. Any graded combination created another way - by
 * the add-card intake, by hand in the back office - would otherwise show the bare
 * card scan, which for a graded listing is a misrepresentation: the buyer is
 * paying for the holder and the label as much as for the card.
 *
 * This is the sweep that makes the rule true of the WHOLE catalogue: every
 * combination whose Grading is a real grader gets the frame for that grader and
 * that grade, and nothing else does.
 *
 * Self-healing: a combination is re-photographed when its slab photo is older
 * than the frame it was cut from. Regenerating the frames (`make slab-frames`)
 * therefore makes every affected listing stale, and the next run fixes it without
 * anyone having to remember which cards used which artwork.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/media/slab-photos.php
 *   ... --force    re-photograph every graded copy, stale or not
 *   ... --dry-run  report what would change and write nothing
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/cutout.php';
require_once __DIR__ . '/../lib/slab.php';

define('FORCE', in_array('--force', $argv ?? [], true));
define('DRY_RUN', in_array('--dry-run', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Slab photos\033[0m\n";

$db = Db::getInstance();
$shopId = (int) Context::getContext()->shop->id;

$gradingGroup = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
      WHERE id_lang = 1 AND name = "Grading"'
);
$conditionGroup = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
      WHERE id_lang = 1 AND name = "Condition"'
);
if (!$gradingGroup || !$conditionGroup) {
    warn('Grading or Condition attribute group missing - run setup.php');
    exit(1);
}

/**
 * Every combination that is actually graded, with its grader and tier.
 *
 * Read at id_lang 1 throughout. The grader and the tier are matched against the
 * frame filenames, which are English by construction - resolving them in the
 * context language would return "Quasi neuf" on the French storefront and match
 * no frame at all.
 *
 * "Ungraded" is excluded in SQL rather than left for slabFramePath() to reject,
 * so a shop full of raw cards does not produce a warning per raw combination.
 */
$slabs = $db->executeS(
    'SELECT pa.id_product_attribute,
            pa.id_product,
            pa.reference,
            grader.name AS grader,
            tier.name AS tier
       FROM ' . _DB_PREFIX_ . 'product_attribute pa
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product AND p.active = 1
       JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac_g
            ON pac_g.id_product_attribute = pa.id_product_attribute
       JOIN ' . _DB_PREFIX_ . 'attribute g ON g.id_attribute = pac_g.id_attribute
            AND g.id_attribute_group = ' . $gradingGroup . '
       JOIN ' . _DB_PREFIX_ . 'attribute_lang grader
            ON grader.id_attribute = g.id_attribute AND grader.id_lang = 1
       JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac_c
            ON pac_c.id_product_attribute = pa.id_product_attribute
       JOIN ' . _DB_PREFIX_ . 'attribute c ON c.id_attribute = pac_c.id_attribute
            AND c.id_attribute_group = ' . $conditionGroup . '
       JOIN ' . _DB_PREFIX_ . 'attribute_lang tier
            ON tier.id_attribute = c.id_attribute AND tier.id_lang = 1
      WHERE grader.name <> "Ungraded"
      ORDER BY pa.reference'
) ?: [];
line(count($slabs) . ' graded combinations');

/** The slab photo already wired to a combination, with its age. */
function currentSlabPhoto(int $combinationId): ?array
{
    $id = (int) Db::getInstance()->getValue(
        'SELECT id_image FROM ' . _DB_PREFIX_ . 'product_attribute_image
          WHERE id_product_attribute = ' . $combinationId . ' ORDER BY id_image DESC'
    );
    if (!$id) {
        return null;
    }
    $path = _PS_PRODUCT_IMG_DIR_ . (new Image($id))->getImgPath() . '.jpg';

    return ['id' => $id, 'shot_at' => is_file($path) ? (int) filemtime($path) : 0];
}

$shot = 0;
$fresh = 0;
$noFrame = [];
$failed = 0;

foreach ($slabs as $slab) {
    $combinationId = (int) $slab['id_product_attribute'];
    $productId = (int) $slab['id_product'];
    $label = (string) $slab['reference'] . ' (' . $slab['grader'] . ' ' . $slab['tier'] . ')';

    $framePath = slabFramePath((string) $slab['grader'], (string) $slab['tier']);
    if ($framePath === null) {
        $noFrame[(string) $slab['grader']] = true;
        continue;
    }

    $existing = currentSlabPhoto($combinationId);
    if (!FORCE && $existing !== null && $existing['shot_at'] >= (int) filemtime($framePath)) {
        ++$fresh;
        continue;
    }

    if (DRY_RUN) {
        line('would shoot ' . $label);
        ++$shot;
        continue;
    }

    $composite = slabComposite($productId, $framePath);
    if ($composite === null) {
        warn('cannot composite ' . $label . ' - no cover scan?');
        ++$failed;
        continue;
    }

    // Dropped only once the new photo exists, so a failure mid-run leaves the
    // listing showing the old slab rather than nothing at all.
    if ($existing !== null) {
        dropSlabImages($combinationId);
    }
    if (attachSlabImage($productId, $composite, $combinationId, $shopId)) {
        ++$shot;
        line($label);
    } else {
        warn('cannot attach photo for ' . $label);
        ++$failed;
    }
    @unlink($composite);
}

line(sprintf('photographed: %d, already current: %d, failed: %d', $shot, $fresh, $failed));
if ($noFrame !== []) {
    warn('no frame artwork for grader: ' . implode(', ', array_keys($noFrame))
        . ' - add a template and re-run media/make-slab-frames.php');
}

if (!DRY_RUN && $shot > 0) {
    Tools::clearAllCache();
}
