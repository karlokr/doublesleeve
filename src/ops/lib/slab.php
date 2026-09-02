<?php
/**
 * Slab artwork: picking the right frame, and photographing a card inside it.
 *
 * Frames are generated per grader and per grade by media/make-slab-frames.php,
 * because the holder AND the label both differ: a PSA 9 sits in a red-labelled
 * holder reading "MINT 9", a CGC 9.5 in a white-labelled one reading "MINT+ 9.5".
 * Showing one generic slab on every graded SKU misrepresents what is being sold,
 * and the label is the first thing a buyer of a slab looks at.
 *
 * Keyed on the two things the combination already knows - its Grading attribute
 * and its Condition tier - so nothing new has to be recorded per listing.
 *
 * Shared by inventory/seed-graded.php (which photographs a slab as it creates it)
 * and media/slab-photos.php (which repairs every graded listing in the shop).
 * They must produce byte-identical output or a re-run would silently reshoot
 * everything, so there is one implementation and both call it.
 */
declare(strict_types=1);

const SLAB_DIR = '/provisioning/assets/slabs/';

/**
 * Frame file for a graded copy, or null when that grader has no artwork.
 *
 * SGC and ACE are deliberately absent rather than approximated: no template for
 * their holders was supplied, and dressing their cards in a PSA slab would be a
 * misrepresentation of the item. They fall back to a plain card scan.
 *
 * @param string $grader Grading attribute value: PSA, BGS, CGC, TAG, ...
 * @param string $tier   Condition tier: "10 Gem Mint", "9.5 Gem Mint", "9", ...
 */
function slabFramePath(string $grader, string $tier): ?string
{
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)/', $tier, $m)) {
        return null;
    }
    $slug = strtolower(trim($grader)) . '-' . str_replace('.', '_', $m[1]);

    /**
     * Beckett's gold Pristine, its Black Label and CGC's gold Pristine are
     * separate LABELS, not a different numeral on the same one - each exists
     * only at 10 and each has its own frame.
     */
    if (preg_match('/black\s*label/i', $tier)) {
        $slug .= '_black_label';
    } elseif (preg_match('/pristine/i', $tier)) {
        $slug .= '_pristine';
    }

    $path = SLAB_DIR . $slug . '.png';

    return is_file($path) ? $path : null;
}

/** Composite the product's cover scan into a slab frame; returns a PNG path. */
function slabComposite(int $productId, string $framePath): ?string
{
    $cover = (int) Db::getInstance()->getValue(
        'SELECT id_image FROM ' . _DB_PREFIX_ . 'image WHERE id_product = ' . $productId . ' ORDER BY cover DESC, position ASC'
    );
    if (!$cover) {
        return null;
    }
    $image = new Image($cover);
    $cardPath = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath() . '.jpg';

    $slab = cutoutLoad($framePath);
    $card = cutoutLoad($cardPath);
    if ($slab === null || $card === null) {
        return null;
    }

    $frameW = imagesx($slab);
    $frameH = imagesy($slab);
    $transparent = static fn (int $x, int $y): bool => ((imagecolorat($slab, $x, $y) >> 24) & 0x7F) > 100;

    // Window detection as in seed-nav-images.php: longest transparent run on a
    // probe row clear of the grade label, then the column through its centre.
    $run = static function (callable $isTransparent, int $length): array {
        $best = [0, -1];
        $start = null;
        for ($i = 0; $i <= $length; ++$i) {
            $t = $i < $length && $isTransparent($i);
            if ($t && $start === null) {
                $start = $i;
            } elseif (!$t && $start !== null) {
                if ($i - 1 - $start > $best[1] - $best[0]) {
                    $best = [$start, $i - 1];
                }
                $start = null;
            }
        }

        return $best;
    };
    $probeY = (int) ($frameH * 0.6);
    [$left, $right] = $run(static fn (int $x) => $transparent($x, $probeY), $frameW);
    $probeX = (int) (($left + $right) / 2);
    [$top, $bottom] = $run(static fn (int $y) => $transparent($probeX, $y), $frameH);

    $windowW = $right - $left + 1;
    $windowH = $bottom - $top + 1;
    if ($windowW < 40 || $windowH < 40) {
        imagedestroy($slab);
        imagedestroy($card);

        return null;
    }

    $scale = max($windowW / imagesx($card), $windowH / imagesy($card));
    $cardW = (int) round(imagesx($card) * $scale);
    $cardH = (int) round(imagesy($card) * $scale);

    $canvas = cutoutCanvas($frameW, $frameH);
    imagealphablending($canvas, false);
    imagecopyresampled(
        $canvas, $card,
        $left + (int) (($windowW - $cardW) / 2),
        $top + (int) (($windowH - $cardH) / 2),
        0, 0, $cardW, $cardH, imagesx($card), imagesy($card)
    );
    imagealphablending($canvas, true);
    imagecopy($canvas, $slab, 0, 0, 0, 0, $frameW, $frameH);
    imagedestroy($slab);
    imagedestroy($card);

    $out = tempnam(sys_get_temp_dir(), 'cc_slab') . '.png';
    $ok = cutoutSave($canvas, $out);
    imagedestroy($canvas);

    return $ok ? $out : null;
}

/** Drop the images wired to one combination, so they can be regenerated. */
function dropSlabImages(int $combinationId): void
{
    $db = Db::getInstance();
    $ids = $db->executeS(
        'SELECT id_image FROM ' . _DB_PREFIX_ . 'product_attribute_image
          WHERE id_product_attribute = ' . $combinationId
    ) ?: [];
    foreach ($ids as $row) {
        // Image::delete() clears the generated thumbnails and the join rows too;
        // unlinking the files alone would leave the listing pointing at nothing.
        (new Image((int) $row['id_image']))->delete();
    }
}

/** Attach a transparent PNG as a NON-cover product image, wired to one combination. */
function attachSlabImage(int $productId, string $pngPath, int $combinationId, int $shopId): bool
{
    $image = new Image();
    $image->id_product = $productId;
    $image->position = Image::getHighestPosition($productId) + 1;
    $image->cover = false;
    if (!$image->add()) {
        return false;
    }
    $image->associateTo($shopId);

    $loaded = cutoutLoad($pngPath);
    if ($loaded === null) {
        return false;
    }
    $base = $image->getPathForCreation();
    // Written through the cutout pipeline, NOT ImageManager::resize - the resize
    // path composites onto an opaque canvas and would fill the slab's rounded
    // corners back in with white.
    cutoutSave($loaded, $base . '.jpg');
    foreach (ImageType::getImagesTypes('products') as $type) {
        $thumb = cutoutResize($loaded, (int) $type['width'], (int) $type['height']);
        cutoutSave($thumb, $base . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
    imagedestroy($loaded);

    Db::getInstance()->execute(
        'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'product_attribute_image (id_product_attribute, id_image)
         VALUES (' . $combinationId . ', ' . (int) $image->id . ')'
    );

    return true;
}
