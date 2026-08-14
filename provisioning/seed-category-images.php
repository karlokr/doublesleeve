<?php
/**
 * Downloads official set logos and attaches them as category images.
 *
 * Gives every one of the 174 set categories real artwork instead of the theme's
 * "no picture" placeholder, which is what makes the set pages and the browse tree
 * look like a real shop rather than an empty install.
 *
 * Idempotent: categories that already have an image are skipped. Pass --force to
 * re-render them, which is what you want after changing how logos are rendered.
 *
 *   make seed-images
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/cutout.php';
require_once __DIR__ . '/lib/logo.php';

// Set categories are TCGplayer groups now, but TCGplayer publishes no set artwork,
// so logos still come from pokemontcg.io - matched to groups when the CSV was built.
const SETS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const SERIES_CSV = '/provisioning/data/pokemon-sets.csv';
/** Logos backfilled from the Bulbagarden Archives - see fetch-set-logos.php. */
const EXTRA_CSV = '/provisioning/data/set-logos-extra.csv';
/** Re-render logos that already exist (use after changing the renderer). */
define('FORCE', in_array('--force', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Set category images\033[0m\n";

$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

/**
 * Set logos are wide, transparent PNGs. Flattening them onto the dark surface
 * colour keeps them looking native instead of showing a white JPEG box.
 */

/**
 * Crop a source image to its non-transparent content.
 *
 * pokemontcg.io logos carry generous transparent margins. Baked into a JPEG those
 * margins become dark padding that cannot be undone in CSS - which is why set
 * tiles rendered a small logo inside a visible inner box. Trimming first means the
 * file IS the logo, and the tile can let it fill the space.
 */
function trimTransparent($source)
{
    $w = imagesx($source);
    $h = imagesy($source);
    $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;

    // Step 2px: logo edges are soft, and exactness costs more than it buys.
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            // 127 = fully transparent in GD's 7-bit alpha channel.
            if ((imagecolorat($source, $x, $y) >> 24 & 0x7F) < 100) {
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }
            }
        }
    }

    if ($maxX <= $minX || $maxY <= $minY) {
        return $source; // fully opaque or fully transparent - nothing to trim
    }

    $pad = 2;
    $cropped = imagecrop($source, [
        'x' => max(0, $minX - $pad),
        'y' => max(0, $minY - $pad),
        'width' => min($w, $maxX - $minX + 1 + $pad * 2),
        'height' => min($h, $maxY - $minY + 1 + $pad * 2),
    ]);

    return $cropped ?: $source;
}

/**
 * Render the logo at its own aspect ratio, on a canvas sized to the logo itself.
 *
 * No letterboxing: the JPEG's edges are the logo's edges, so the tile shows art
 * rather than a box. The fill colour still matches --cc-surface so the flattened
 * corners disappear into the card.
 */
function renderTight($source, int $maxW, int $maxH, string $destination, bool $dim = false): void
{
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $scale = min($maxW / $srcW, $maxH / $srcH, 1.0);
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));

    /**
     * Transparent canvas, never a painted one.
     *
     * This used to composite the logo onto #121A2C - the theme's surface colour,
     * hardcoded - which baked an opaque box into the file. It stopped matching the
     * page the moment the palette changed, which is exactly what a hardcoded
     * background always eventually does.
     */
    $canvas = cutoutCanvas($newW, $newH);
    imagealphablending($canvas, false);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    if ($dim) {
        // Knock the borrowed era logo back so it reads as a generation marker
        // rather than this set's own art. Done on the ALPHA channel, because
        // veiling with a colour would reintroduce the background.
        fadeImage($canvas, 0.45);
    }

    cutoutSave($canvas, $destination);
    imagedestroy($canvas);
}

/**
 * Multiplies an image's alpha, so a logo can be knocked back without painting
 * anything behind it.
 */
function fadeImage($image, float $factor): void
{
    $width = imagesx($image);
    $height = imagesy($image);
    imagealphablending($image, false);

    for ($y = 0; $y < $height; ++$y) {
        for ($x = 0; $x < $width; ++$x) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $faded = (int) min(127, round(127 - (127 - $alpha) * $factor));
            imagesetpixel($image, $x, $y, imagecolorallocatealpha(
                $image, ($rgba >> 16) & 255, ($rgba >> 8) & 255, $rgba & 255, $faded
            ));
        }
    }
}

/** Render the logo centred on a transparent canvas of exactly $boxW x $boxH. */
function renderOnDark($source, int $boxW, int $boxH, string $destination): void
{
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $scale = min($boxW / $srcW, $boxH / $srcH, 1.0);
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));

    $canvas = cutoutCanvas($boxW, $boxH);
    imagealphablending($canvas, false);
    imagecopyresampled(
        $canvas, $source,
        (int) (($boxW - $newW) / 2), (int) (($boxH - $newH) / 2),
        0, 0, $newW, $newH, $srcW, $srcH
    );
    cutoutSave($canvas, $destination);
    imagedestroy($canvas);
}

/**
 * @param bool $isFallback true when this is the era's logo standing in for a set
 *                         that has none of its own - rendered dimmer so the grid
 *                         does not imply it is that set's real artwork.
 */
function writeCategoryImage(int $categoryId, string $url, bool $isFallback = false): bool
{
    $context = stream_context_create(['http' => ['timeout' => 25, 'user_agent' => 'DoubleSleeve/1.0']]);
    $bytes = @file_get_contents($url, false, $context);
    if ($bytes === false || strlen($bytes) < 512) {
        return false;
    }

    $source = @imagecreatefromstring($bytes);
    if ($source === false) {
        return false;
    }

    $source = trimTransparent($source);

    // The base file is what the set directory renders, so it is tight-cropped.
    $base = _PS_CAT_IMG_DIR_ . $categoryId;
    renderTight($source, 900, 420, $base . '.jpg', $isFallback);

    // PrestaShop's declared types still need exact dimensions (category headers
    // size to them), so those keep the fixed-canvas renderer.
    foreach (ImageType::getImagesTypes('categories') as $type) {
        renderOnDark($source, (int) $type['width'], (int) $type['height'], $base . '-' . $type['name'] . '.jpg');
    }

    imagedestroy($source);

    return true;
}

if (!is_readable(SETS_CSV)) {
    warn('sets CSV missing');
    exit(1);
}

// Only 114 of 217 groups carry a logo_url in the CSV. The rest are recoverable by
// matching TCGplayer's name against pokemontcg.io's catalogue - see lib/logo.php
// for the spelling rules, and why the match is scoped to the resolved era.
$seriesMap = loadSeriesMap(SERIES_CSV);
$logoIndex = loadLogoIndex(SERIES_CSV);

// pokemontcg.io lags new releases and omits some groups entirely, so a committed
// backfill fills the gap by group id - no fuzzy matching needed at this point.
$extraLogos = [];
if (is_readable(EXTRA_CSV)) {
    $fh = fopen(EXTRA_CSV, 'r');
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) >= 3 && trim((string) $r[2]) !== '') {
            $extraLogos[(int) $r[0]] = (string) $r[2];
        }
    }
    fclose($fh);
    line('backfilled logo entries loaded: ' . count($extraLogos));
}

$handle = fopen(SETS_CSV, 'r');
$header = fgetcsv($handle);
$done = 0;
$skipped = 0;
$failed = 0;
$recovered = 0;
$backfilled = 0;
$noLogo = [];     // groupId => [categoryId, era]
$eraLogo = [];    // era => logo url of its earliest set, for the fallback pass

while (($row = fgetcsv($handle)) !== false) {
    $record = array_combine($header, array_pad($row, count($header), ''));
    $logo = trim((string) ($record['logo_url'] ?? ''));
    $groupId = (int) ($record['group_id'] ?? 0);
    if ($groupId === 0) {
        continue;
    }

    $name = (string) ($record['name'] ?? '');
    $era = resolveEra($name, (string) ($record['published_on'] ?? ''), $seriesMap);

    if ($logo === '') {
        $logo = (string) (resolveLogo($name, $era, $logoIndex) ?? '');
        if ($logo !== '') {
            ++$recovered;
        }
    }
    if ($logo === '' && isset($extraLogos[$groupId])) {
        $logo = $extraLogos[$groupId];
        ++$backfilled;
    }
    // Earliest set in an era is its base set - the right face for the whole era.
    if ($logo !== '' && !isset($eraLogo[$era])) {
        $eraLogo[$era] = $logo;
    }

    // Resolve through the group -> category mapping rather than by name, so a
    // TCGplayer set rename doesn't silently orphan its artwork.
    $categoryId = (int) Db::getInstance()->getValue(
        'SELECT id_category FROM ' . _DB_PREFIX_ . 'tcg_group_category WHERE group_id = ' . $groupId
    );
    if (!$categoryId) {
        continue;
    }

    if ($logo === '') {
        $noLogo[$groupId] = [$categoryId, $era];
        continue;
    }

    if (!FORCE && file_exists(_PS_CAT_IMG_DIR_ . $categoryId . '.jpg')) {
        ++$skipped;
        continue;
    }

    if (writeCategoryImage($categoryId, $logo)) {
        ++$done;
    } else {
        ++$failed;
    }

    if (($done + $failed) % 40 === 0 && $done + $failed > 0) {
        echo "     ... $done images\n";
    }
}
fclose($handle);

line("set logos written: $done (name-matched: $recovered, backfilled: $backfilled, skipped $skipped, failed $failed)");

// ---------------------------------------------------------------------------
// fallback: a set with no artwork of its own wears its era's face
// ---------------------------------------------------------------------------
// Sets like "ME: 30th Celebration" and the McDonald's promo runs are simply not in
// pokemontcg.io's catalogue - it holds 174 sets against TCGplayer's 217, verified
// against the live API. Rather than leave those tiles bare they inherit the era's
// base-set logo, which is still true information: it tells you the generation.
$fallback = 0;
$plate = 0;
foreach ($noLogo as [$categoryId, $era]) {
    if (!isset($eraLogo[$era])) {
        ++$plate;   // no era artwork either - the tile renders its abbreviation
        continue;
    }
    if (!FORCE && file_exists(_PS_CAT_IMG_DIR_ . $categoryId . '.jpg')) {
        continue;
    }
    if (writeCategoryImage($categoryId, $eraLogo[$era], true)) {
        ++$fallback;
    }
}
line("era-fallback logos: $fallback");
if ($plate > 0) {
    warn("$plate sets have neither own nor era artwork - these render a wordmark plate");
}

Tools::clearAllCache();
