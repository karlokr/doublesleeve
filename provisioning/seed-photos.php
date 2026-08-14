<?php
/**
 * Stock backs + per-copy photo sets, with generated placeholders.
 *
 * Two separate photo systems, deliberately:
 *
 *   STOCK photos  - reference imagery for the printing. Auto-collected from the
 *                   catalogues, front already present; this adds a back. Stored as
 *                   normal PrestaShop product images so they appear in the gallery.
 *
 *   COPY photos   - your own photographs of one physical card, several per copy.
 *                   Stored OUTSIDE PrestaShop's image system, because a copy photo
 *                   must never leak into the product gallery - it belongs to one
 *                   serial, not to the printing.
 *
 * Every image generated here is stamped PLACEHOLDER. They exist so the feature can
 * be evaluated before the intake pipeline supplies real photography - they are not
 * pictures of cards anyone owns.
 *
 *   make seed-photos
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/cutout.php';

const COPY_DIR = _PS_IMG_DIR_ . 'cc-copies/';
/** How many products get placeholder copy photos, highest value first. */
const SAMPLE_PRODUCTS = 6;
/** Under this, a single is bulk: we sell it by condition, never by photo. */
const BULK_PRICE_CEILING = 15.00;
/** Drop a real card-back scan here to replace the drawn placeholder. */
const BACK_ASSET_DIR = '/provisioning/assets/';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Photos: stock backs + copy sets\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;

$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'card_copy_image (
    id_copy_image INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_copy       INT UNSIGNED NOT NULL,
    filename      VARCHAR(128) NOT NULL,
    side          ENUM("front","back","detail") NOT NULL DEFAULT "front",
    position      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
    date_add      DATETIME NOT NULL,
    PRIMARY KEY (id_copy_image),
    KEY idx_copy (id_copy, position)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
line('card_copy_image table ready');

if (!is_dir(COPY_DIR)) {
    mkdir(COPY_DIR, 0775, true);
}

// ---------------------------------------------------------------------------
// drawing helpers
// ---------------------------------------------------------------------------
function stamp($image, string $top, string $bottom): void
{
    $w = imagesx($image);
    $h = imagesy($image);

    // Translucent band so the underlying art stays readable but nobody mistakes
    // this for a real photograph of stock.
    $band = imagecreatetruecolor($w, 46);
    imagefilledrectangle($band, 0, 0, $w, 46, imagecolorallocate($band, 8, 11, 20));
    imagecopymerge($image, $band, 0, 0, 0, 0, $w, 46, 82);
    imagecopymerge($image, $band, 0, $h - 46, 0, 0, $w, 46, 82);
    imagedestroy($band);

    $white = imagecolorallocate($image, 240, 244, 252);
    $cyan = imagecolorallocate($image, 34, 211, 238);
    imagestring($image, 5, 12, 14, $top, $cyan);
    imagestring($image, 3, 12, $h - 32, $bottom, $white);
    imagerectangle($image, 1, 1, $w - 2, $h - 2, $cyan);
}

/**
 * The reference card back.
 *
 * Every modern English Pokémon card shares ONE back, so this is a single asset for
 * the whole catalogue rather than something to fetch per card - pokemontcg.io's
 * API only serves fronts, and its /back.png returns a 404 whose BODY is a PNG, so
 * a naive fetch "succeeds" and stores the wrong image. Check status, not bytes.
 *
 * The shipped asset is the standard English back from the Bulbagarden archives.
 * It is ordinary product imagery for a card shop - the same basis on which the
 * card fronts are already imported - so it is committed rather than drawn.
 *
 * The drawn placeholder remains only as a fallback for a checkout with no asset.
 */
function realBackPath(): ?string
{
    foreach (['card-back.jpg', 'card-back.png'] as $name) {
        $candidate = BACK_ASSET_DIR . $name;
        if (is_file($candidate) && @imagecreatefromstring((string) file_get_contents($candidate)) !== false) {
            return $candidate;
        }
    }

    return null;
}

/** A neutral card-back placeholder - no real card-back artwork is reproduced. */
function makeBack(int $w, int $h, string $caption): \GdImage
{
    $image = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($image, 18, 26, 44);
    imagefilledrectangle($image, 0, 0, $w, $h, $bg);

    $ring = imagecolorallocate($image, 40, 56, 92);
    for ($r = min($w, $h) / 3; $r > 10; $r -= 18) {
        imageellipse($image, (int) ($w / 2), (int) ($h / 2), (int) $r * 2, (int) $r * 2, $ring);
    }
    $accent = imagecolorallocate($image, 129, 140, 248);
    imagefilledellipse($image, (int) ($w / 2), (int) ($h / 2), 26, 26, $accent);

    stamp($image, 'PLACEHOLDER', $caption);

    return $image;
}

/**
 * Written as PNG, into a .jpg path.
 *
 * The extension is PrestaShop's convention and the templates depend on it; the
 * bytes are PNG, which is the only one of the two that can carry alpha. Saving
 * JPEG here is why the card back could never have a transparent background no
 * matter what the source asset was.
 */
function saveJpeg($image, string $path): void
{
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagepng($image, $path, 6);
}

/** Write the card back (real scan if present, drawn placeholder otherwise). */
function writeBackFiles(Image $image): void
{
    $path = $image->getPathForCreation();
    $real = realBackPath();
    $back = $real !== null
        ? imagecreatefromstring((string) file_get_contents($real))
        : makeBack(600, 825, 'STOCK BACK - REFERENCE');

    // The back is a card, so it is cut to the same rounded rectangle as every
    // front - otherwise it is the one image in the gallery with square corners
    // and an opaque surround.
    $cut = cutoutCard($back);
    if ($cut !== null) {
        imagedestroy($back);
        $back = $cut;
    }

    saveJpeg($back, $path . '.jpg');
    foreach (ImageType::getImagesTypes('products') as $type) {
        // Not ImageManager::resize - it composites onto an opaque canvas and puts
        // the background straight back.
        $thumb = cutoutResize($back, (int) $type['width'], (int) $type['height']);
        saveJpeg($thumb, $path . '-' . $type['name'] . '.jpg');
        imagedestroy($thumb);
    }
    imagedestroy($back);
}

// ---------------------------------------------------------------------------
// 1. stock backs - a second product image for every serialised single
// ---------------------------------------------------------------------------
$singles = $db->executeS(
    'SELECT DISTINCT cc.id_product, pl.link_rewrite
       FROM ' . _DB_PREFIX_ . 'card_copy cc
       JOIN ' . _DB_PREFIX_ . 'product_lang pl
            ON pl.id_product = cc.id_product AND pl.id_lang = ' . $defaultLang
) ?: [];

$backsAdded = 0;
$backsReplaced = 0;
foreach ($singles as $row) {
    $productId = (int) $row['id_product'];

    $existingBack = (int) $db->getValue(
        'SELECT i.id_image FROM ' . _DB_PREFIX_ . 'image i
           JOIN ' . _DB_PREFIX_ . 'image_lang il ON il.id_image = i.id_image AND il.id_lang = ' . $defaultLang . '
          WHERE i.id_product = ' . $productId . ' AND il.legend LIKE "%back%"'
    );

    // A back already exists. Rewrite it in place when a real scan is now available
    // so the drawn placeholders get replaced instead of surviving forever - the
    // image row and its id stay put, only the pixels change.
    if ($existingBack) {
        if (realBackPath() === null) {
            continue;
        }
        $image = new Image($existingBack);
        if (!Validate::isLoadedObject($image)) {
            continue;
        }
        writeBackFiles($image);
        ++$backsReplaced;
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
    foreach (Language::getLanguages(false) as $language) {
        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'image_lang (id_image, id_lang, legend)
             VALUES (' . (int) $image->id . ', ' . (int) $language['id_lang'] . ', "Card back (reference)")
             ON DUPLICATE KEY UPDATE legend = VALUES(legend)'
        );
    }

    writeBackFiles($image);
    ++$backsAdded;
}
line("stock back images added: $backsAdded, replaced: $backsReplaced");
if (realBackPath() === null) {
    warn('no provisioning/assets/card-back.jpg - backs are DRAWN placeholders.');
    warn('drop a real scan there and re-run to replace them.');
} else {
    line('stock backs sourced from ' . realBackPath());
}

// ---------------------------------------------------------------------------
// 2. per-copy photo sets for a sample of products
// ---------------------------------------------------------------------------
$sample = $db->executeS(
    'SELECT DISTINCT cc.id_product, p.price
       FROM ' . _DB_PREFIX_ . 'card_copy cc
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cc.id_product
      ORDER BY p.price DESC
      LIMIT ' . SAMPLE_PRODUCTS
) ?: [];

$copyPhotos = 0;
$copiesDone = 0;

foreach ($sample as $row) {
    $productId = (int) $row['id_product'];

    // The stock front is the base for the placeholder, so tiles look like cards
    // rather than abstract boxes - but every one is stamped.
    $coverId = (int) $db->getValue(
        'SELECT id_image FROM ' . _DB_PREFIX_ . 'image
          WHERE id_product = ' . $productId . ' ORDER BY cover DESC, position ASC'
    );
    $coverPath = null;
    if ($coverId) {
        $cover = new Image($coverId);
        $candidate = _PS_PRODUCT_IMG_DIR_ . $cover->getImgPath() . '.jpg';
        if (file_exists($candidate)) {
            $coverPath = $candidate;
        }
    }

    $copies = $db->executeS(
        'SELECT id_copy, copy_uid FROM ' . _DB_PREFIX_ . 'card_copy
          WHERE id_product = ' . $productId . ' AND status = "available"
          ORDER BY id_copy'
    ) ?: [];

    foreach ($copies as $copy) {
        $copyId = (int) $copy['id_copy'];
        $uid = (string) $copy['copy_uid'];

        $existing = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy_image WHERE id_copy = ' . $copyId
        );
        if ($existing) {
            continue;
        }

        /**
         * A real intake shoots more than two frames of anything valuable: front,
         * back, then close-ups of the corners and surface that a buyer would want
         * before spending four figures. The model supports any number.
         */
        $shots = [['front', 0], ['back', 1], ['detail', 2], ['detail', 3]];

        foreach ($shots as $index => [$side, $position]) {
            $label = $side === 'detail'
                ? ($index === 2 ? 'CORNERS' : 'SURFACE')
                : strtoupper($side);
            $filename = $uid . '-' . $side . '-' . $position . '.jpg';

            if ($side === 'front' && $coverPath) {
                // Not all ".jpg" files under img/p actually are - some sources
                // wrote PNG bytes with a jpg name, so detect from content.
                $image = @imagecreatefromstring((string) file_get_contents($coverPath));
            }
            if ($side === 'front' && $coverPath && $image !== false) {
                stamp($image, 'PLACEHOLDER', $uid . ' - FRONT');
            } elseif ($side === 'detail' && $coverPath) {
                // Crop into the card so the extra frames read as close-ups.
                $full = @imagecreatefromstring((string) file_get_contents($coverPath));
                if ($full !== false) {
                    $w = imagesx($full);
                    $h = imagesy($full);
                    $image = imagecreatetruecolor(600, 400);
                    $srcX = $index === 2 ? 0 : (int) ($w * 0.35);
                    $srcY = $index === 2 ? 0 : (int) ($h * 0.45);
                    imagecopyresampled($image, $full, 0, 0, $srcX, $srcY, 600, 400,
                        (int) ($w * 0.45), (int) ($h * 0.3));
                    imagedestroy($full);
                    stamp($image, 'PLACEHOLDER', $uid . ' - ' . $label);
                } else {
                    $image = makeBack(600, 400, $uid . ' - ' . $label);
                }
            } elseif ($side === 'back' && realBackPath() !== null) {
                $image = imagecreatefromstring((string) file_get_contents((string) realBackPath()));
                stamp($image, 'PLACEHOLDER', $uid . ' - BACK');
            } else {
                $image = makeBack(600, 825, $uid . ' - ' . $label);
            }

            saveJpeg($image, COPY_DIR . $filename);
            imagedestroy($image);

            $db->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'card_copy_image
                    (id_copy, filename, side, position, is_placeholder, date_add)
                 VALUES (' . $copyId . ', "' . pSQL($filename) . '", "' . pSQL($side) . '", '
                 . $position . ', 1, NOW())'
            );
            ++$copyPhotos;
        }

        $db->execute(
            'UPDATE ' . _DB_PREFIX_ . 'card_copy SET photo_state = "captured" WHERE id_copy = ' . $copyId
        );
        ++$copiesDone;
    }
}

line("copies photographed: $copiesDone ($copyPhotos placeholder images)");

// Copies photographed before the real back asset existed still hold a drawn back.
// Rewrite those frames in place - same filenames, so no DB rows change.
$refreshed = 0;
if (realBackPath() !== null) {
    foreach ($db->executeS(
        'SELECT ci.filename FROM ' . _DB_PREFIX_ . 'card_copy_image ci
          WHERE ci.side = "back" AND ci.is_placeholder = 1'
    ) ?: [] as $row) {
        $file = COPY_DIR . (string) $row['filename'];
        if (!is_file($file)) {
            continue;
        }
        $back = imagecreatefromstring((string) file_get_contents((string) realBackPath()));
        stamp($back, 'PLACEHOLDER', pathinfo($file, PATHINFO_FILENAME));
        saveJpeg($back, $file);
        imagedestroy($back);
        ++$refreshed;
    }
}
line("copy back frames refreshed from the real scan: $refreshed");
warn('every generated image is stamped PLACEHOLDER - replace via the intake pipeline');

// ---------------------------------------------------------------------------
// 3. photo policy - which SKUs we never intend to photograph
// ---------------------------------------------------------------------------
/**
 * Bulk is a business decision, not a backlog: nobody shoots four frames of a
 * $2 common they hold thirty of. Those copies are marked stock_only so the
 * storefront says "we do not photograph these" instead of "photo pending",
 * which would be a promise we are not going to keep.
 *
 * The threshold is a seeding convenience. In production this is set at intake.
 */
$bulk = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy cc
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cc.id_product
      WHERE p.price < ' . BULK_PRICE_CEILING . ' AND cc.photo_state = "pending"'
);
$db->execute(
    'UPDATE ' . _DB_PREFIX_ . 'card_copy cc
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cc.id_product
        SET cc.photo_policy = "stock_only"
      WHERE p.price < ' . BULK_PRICE_CEILING . ' AND cc.photo_state = "pending"'
);
line("copies marked stock_only (bulk, under \$" . BULK_PRICE_CEILING . "): $bulk");

Tools::clearAllCache();
