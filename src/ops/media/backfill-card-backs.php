<?php
/**
 * Gives every card listing its back as a second photo.
 *
 * add-card.php attaches the back when a card enters stock, but every card that
 * predates that pipeline has only its front. This is the one-time catch-up, and
 * it is safe to re-run: a product that already carries more than one image is
 * left alone.
 *
 * Which back is correct is decided per card by lib/cardback.php - Western cards
 * share one back, Japanese cards had two and changed in 2002, so the set's
 * release date picks between them.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/media/backfill-card-backs.php
 *   ... --force   re-attach even where a second image already exists
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/cutout.php';
require_once __DIR__ . '/../lib/cardback.php';

define('FORCE', in_array('--force', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Card backs\033[0m\n";

$db = Db::getInstance();
$shopId = (int) Context::getContext()->shop->id;

/**
 * Every card, with the region it belongs to and the release date of its set.
 *
 * Region comes from the derived feature and the date from the TCGplayer group
 * map, so both are facts about the SET rather than anything entered per card.
 */
$cards = $db->executeS(
    'SELECT ci.id_product,
            COALESCE(rv.value, "Western") AS region,
            COALESCE(g.published_on, "") AS published_on,
            (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = ci.id_product) AS images
       FROM ' . _DB_PREFIX_ . 'card_identity ci
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = ci.id_product AND p.active = 1
       LEFT JOIN ' . _DB_PREFIX_ . 'feature_product fp
            ON fp.id_product = ci.id_product
            AND fp.id_feature = (SELECT id_feature FROM ' . _DB_PREFIX_ . 'feature_lang
                                  WHERE id_lang = 1 AND name = "Region" LIMIT 1)
       LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang rv
            ON rv.id_feature_value = fp.id_feature_value AND rv.id_lang = 1
       LEFT JOIN ' . _DB_PREFIX_ . 'tcg_group_category g ON g.id_category = ci.id_category_set'
) ?: [];
line(count($cards) . ' card listings');

/** Backs are the same handful of files, so cut each one once. */
$prepared = [];
$attached = 0;
$skipped = 0;
$noBack = [];

foreach ($cards as $card) {
    $productId = (int) $card['id_product'];
    if (!FORCE && (int) $card['images'] > 1) {
        ++$skipped;
        continue;
    }
    // A card with no front yet is not ready for a back either.
    if ((int) $card['images'] === 0) {
        ++$skipped;
        continue;
    }

    $region = (string) $card['region'];
    $published = (string) $card['published_on'];
    $path = cardBackPath($region, $published);
    if ($path === null) {
        $noBack[$region] = true;
        continue;
    }

    if (!isset($prepared[$path])) {
        $loaded = cutoutLoad($path);
        $prepared[$path] = $loaded !== null ? cutoutCard($loaded) : false;
        if ($loaded !== null) {
            imagedestroy($loaded);
        }
    }
    $cut = $prepared[$path];
    if ($cut === false) {
        warn('unreadable back asset: ' . $path);
        continue;
    }

    $image = new Image();
    $image->id_product = $productId;
    $image->position = Image::getHighestPosition($productId) + 1;
    $image->cover = false;
    if (!$image->add()) {
        continue;
    }
    $image->associateTo($shopId);

    $base = $image->getPathForCreation();
    // Written through the cutout pipeline, not ImageManager: that one composites
    // onto an opaque canvas and would fill the rounded corners back in.
    cutoutSave($cut, $base . '.jpg');
    foreach (ImageType::getImagesTypes('products') as $type) {
        $thumb = cutoutResize($cut, (int) $type['width'], (int) $type['height']);
        cutoutSave($thumb, $base . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
    ++$attached;

    if ($attached % 50 === 0) {
        echo "     ... $attached backs attached\n";
    }
}

foreach ($prepared as $cut) {
    if ($cut !== false) {
        imagedestroy($cut);
    }
}

line("backs attached: $attached (already had one, or no front yet: $skipped)");
if ($noBack !== []) {
    warn('no back artwork for region: ' . implode(', ', array_keys($noBack)));
}

Tools::clearAllCache();
