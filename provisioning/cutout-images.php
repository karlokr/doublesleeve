<?php
/**
 * Gives every fetched image a transparent background.
 *
 * Replaces trim-images.php, which cropped the white margin but left the result
 * opaque - and which had silently stopped working entirely: it opened files with
 * imagecreatefromjpeg(), and PrestaShop writes PNG data into those .jpg paths
 * whenever PS_IMAGE_QUALITY is png, so most of the catalogue was never touched.
 *
 * Cards are cut to their rounded rectangle; set logos have their background
 * flooded out from the edges. See lib/cutout.php for why those are different jobs.
 *
 * Every size PrestaShop declares is regenerated here rather than through
 * ImageManager::resize, which composites onto an opaque canvas and would put the
 * background straight back.
 *
 *   make cutout-images
 *   ... --force   redo images that already carry alpha
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/cutout.php';

define('FORCE', in_array('--force', $argv ?? [], true));
/**
 * Redo only the products that are NOT cards.
 *
 * A blanket --force re-encodes every card and every thumbnail - 8,000 PNG writes
 * for the sake of the 54 photographs that actually changed treatment. This targets
 * exactly those.
 */
define('PHOTOS_ONLY', in_array('--photos', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Cutting images out of their backgrounds\033[0m\n";

/** Already has real transparency somewhere along its border? */
function hasAlpha(string $path): bool
{
    $image = cutoutLoad($path);
    if ($image === null) {
        return false;
    }
    $width = imagesx($image);
    $height = imagesy($image);

    $found = false;
    foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
        if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 100) {
            $found = true;
            break;
        }
    }
    imagedestroy($image);

    return $found;
}

// ---------------------------------------------------------------------------
// product images - card cutouts
// ---------------------------------------------------------------------------
$rows = Db::getInstance()->executeS(
    'SELECT id_image, id_product FROM ' . _DB_PREFIX_ . 'image ORDER BY id_image'
) ?: [];

/** Which products are cards. Anything else is photographed, not scanned. */
$cardProducts = [];
foreach (Db::getInstance()->executeS(
    'SELECT id_product FROM ' . _DB_PREFIX_ . 'card_identity'
) ?: [] as $row) {
    $cardProducts[(int) $row['id_product']] = true;
}
line(count($cardProducts) . ' products are cards; the rest get background removal');

$done = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    $image = new Image((int) $row['id_image']);
    if (!Validate::isLoadedObject($image)) {
        continue;
    }
    $base = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath();
    $source = $base . '.jpg';
    if (!file_exists($source)) {
        continue;
    }
    $isCard = isset($cardProducts[(int) $row['id_product']]);
    if (PHOTOS_ONLY && $isCard) {
        ++$skipped;
        continue;
    }
    if (!FORCE && !PHOTOS_ONLY && hasAlpha($source)) {
        ++$skipped;
        continue;
    }

    $loaded = cutoutLoad($source);
    if ($loaded === null) {
        ++$failed;
        continue;
    }

    /**
     * Cards get the rounded-rectangle cut; everything else gets its background
     * flooded out.
     *
     * cutoutCard() only alphas the CORNERS - which is right for a card, because a
     * card scan is the card edge to edge. A sealed product is photographed on a
     * white sweep with space around it, so running it through the card cut left the
     * sweep completely intact: the booster box arrived on the homepage still sitting
     * on a white rectangle.
     */
    $cut = $isCard ? cutoutCard($loaded) : cutoutLogo($loaded);
    imagedestroy($loaded);
    if ($cut === null) {
        ++$failed;
        continue;
    }

    // The .jpg extension is PrestaShop's convention and the templates depend on
    // it; the BYTES are PNG, which is what carries the alpha.
    cutoutSave($cut, $source);
    foreach (ImageType::getImagesTypes('products') as $type) {
        $thumb = cutoutResize($cut, (int) $type['width'], (int) $type['height']);
        cutoutSave($thumb, $base . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
    imagedestroy($cut);
    ++$done;

    if ($done % 50 === 0) {
        echo "     ... $done cards cut\n";
    }
}
line("cards cut out: $done (already transparent: $skipped, unreadable: $failed)");

// ---------------------------------------------------------------------------
// category images - set logos
// ---------------------------------------------------------------------------
$logos = 0;
$logosKept = 0;

foreach (PHOTOS_ONLY ? [] : (Db::getInstance()->executeS(
    'SELECT id_category FROM ' . _DB_PREFIX_ . 'category ORDER BY id_category'
) ?: []) as $row) {
    $categoryId = (int) $row['id_category'];
    $path = _PS_CAT_IMG_DIR_ . $categoryId . '.jpg';
    if (!file_exists($path)) {
        continue;
    }
    if (!FORCE && hasAlpha($path)) {
        ++$logosKept;
        continue;
    }

    $loaded = cutoutLoad($path);
    if ($loaded === null) {
        continue;
    }
    $cut = cutoutLogo($loaded);
    imagedestroy($loaded);
    if ($cut === null) {
        ++$logosKept;
        continue;
    }

    cutoutSave($cut, $path);
    foreach (ImageType::getImagesTypes('categories') as $type) {
        $thumb = cutoutResize($cut, (int) $type['width'], (int) $type['height']);
        cutoutSave($thumb, _PS_CAT_IMG_DIR_ . $categoryId . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
    imagedestroy($cut);
    ++$logos;
}
line("set logos cut out: $logos (already transparent: $logosKept)");

Tools::clearAllCache();
