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
require_once __DIR__ . '/../lib/cutout.php';
require_once __DIR__ . '/../lib/logo.php';
require_once __DIR__ . '/../lib/era-jp.php';

// Set categories are TCGplayer groups now, but TCGplayer publishes no set artwork,
// so logos still come from pokemontcg.io - matched to groups when the CSV was built.
const SETS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const SERIES_CSV = '/provisioning/data/pokemon-sets.csv';
/** Logos backfilled from the Bulbagarden Archives - see fetch-set-logos.php. */
const EXTRA_CSV = '/provisioning/data/set-logos-extra.csv';
/** Japanese logos, resolved separately - see catalog/fetch-set-logos-jp.php. */
const LOGOS_JP_CSV = '/provisioning/data/set-logos-jp.csv';
const GROUPS_JP_CSV = '/provisioning/data/tcgplayer-groups-jp.csv';
/** Higher-resolution replacements - see catalog/upgrade-set-logos.php. */
const LOGOS_HIRES_CSV = '/provisioning/data/set-logos-hires.csv';

/**
 * Japanese block => the Western era whose logo stands in for it.
 *
 * Only the blocks whose names differ. ADV and PCG have no Western counterpart at
 * all - they ran while the West was in EX - and Japan's first block is what the
 * West published as Base under Wizards of the Coast.
 */
const JP_ERA_TO_WESTERN = [
    'MEGA' => 'Mega Evolution',
    'Base' => 'Base / WotC',
    'ADV' => 'EX',
    'PCG' => 'EX',
    'VS' => 'Neo',
    'LEGEND' => 'HeartGold & SoulSilver',
];
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
    $cropX = max(0, $minX - $pad);
    $cropY = max(0, $minY - $pad);
    $cropW = min($w - $cropX, $maxX - $minX + 1 + $pad * 2);
    $cropH = min($h - $cropY, $maxY - $minY + 1 + $pad * 2);

    /**
     * Copied onto a transparent canvas rather than imagecrop()'d.
     *
     * imagecrop() builds its destination with imagecreatetruecolor(), which is
     * filled with OPAQUE BLACK, and the copy does not carry the alpha channel
     * across - so every transparent pixel inside the crop came out solid black.
     * It happened to be invisible for logos that had just been through
     * cutoutLogo(), whose canvas survives the crop, and struck exactly the ones
     * that arrived already transparent and were therefore left alone: they were
     * the images that had transparency to lose. That is how a set logo ended up
     * rendering as a black slab on the tile.
     */
    $cropped = cutoutCanvas($cropW, $cropH);
    imagealphablending($cropped, false);
    imagecopy($cropped, $source, 0, 0, $cropX, $cropY, $cropW, $cropH);

    return $cropped;
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

    /**
     * Palette images become truecolour before anything touches them, exactly as
     * cutoutLoad() does - this path builds from a byte string rather than a file,
     * so it does not go through that function and used to skip the conversion.
     */
    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }
    imagealphablending($source, false);
    imagesavealpha($source, true);

    /**
     * Strip a flat background BEFORE trimming.
     *
     * Not every logo arrives on transparency. Plenty of the Japanese ones are
     * scans or exports sitting on a solid white plate, and nothing here removed
     * it: trimTransparent() looks for transparent MARGINS, and an image that is
     * opaque everywhere has none to find, so the plate was written into the file
     * and showed as a white box on the dark set tile. The cut has to happen
     * first - once the plate is transparent, trimming can crop to the artwork.
     */
    $cut = cutoutLogo($source);
    if ($cut !== null) {
        imagedestroy($source);
        $source = $cut;
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


/**
 * Which set should stand in for its era.
 *
 * "Earliest released" was the rule and it is wrong: an era's opener is routinely
 * a starter product, so every logo-less XY set inherited the KALOS STARTER SET
 * wordmark instead of the plain XY one. What a reader means by "the era's logo"
 * is its BASE SET - the flagship expansion the block is named after.
 *
 * So: a set whose name IS the era (or the era plus "Base Set") wins outright;
 * failing that the shortest name, which is the most canonical of the remaining
 * candidates. Promos, energy subsets, trainer kits, decks and jumbo/oversized
 * runs are never an era's face - they are side products that happen to carry the
 * era's dates.
 *
 * @param array<int, array{name: string, logo: string}> $candidates
 */
function eraFaceLogo(string $era, array $candidates): ?string
{
    if ($candidates === []) {
        return null;
    }
    $eraKey = strtolower(trim($era));

    /**
     * TCGplayer prefixes names with the release code - "ME01: Mega Evolution",
     * "SV08: Surging Sparks" - so a raw comparison never matches the era and the
     * flagship loses to whatever happens to have the shortest string. Compare on
     * the display name.
     */
    $plain = static fn (string $name): string => strtolower(trim(
        (string) preg_replace('/^[A-Za-z0-9]{1,6}:\s*/', '', trim($name))
    ));

    $flagship = array_values(array_filter(
        $candidates,
        static fn (array $c) => !preg_match(
            '/promo|energ|trainer kit|starter|jumbo|oversiz|deck|gift set|collection sheet|file/i',
            $c['name']
        )
    ));
    $pool = $flagship !== [] ? $flagship : $candidates;

    foreach ($pool as $candidate) {
        $name = $plain($candidate['name']);
        if ($name === $eraKey
            || $name === $eraKey . ' base set'
            || $name === 'base set'
            || str_contains($name, 'base set')) {
            return $candidate['logo'];
        }
    }

    usort($pool, static fn ($a, $b) => strlen($plain($a['name'])) <=> strlen($plain($b['name'])));

    return $pool[0]['logo'];
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

/**
 * Resolution upgrades, and the only source that OVERRIDES rather than fills a
 * gap: these were accepted precisely because they beat what is already on disk.
 */
$hiresLogos = [];
if (is_readable(LOGOS_HIRES_CSV)) {
    $fh = fopen(LOGOS_HIRES_CSV, 'r');
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) >= 3 && trim((string) $r[2]) !== '') {
            $hiresLogos[(int) $r[0]] = (string) $r[2];
        }
    }
    fclose($fh);
    line('high-resolution upgrades loaded: ' . count($hiresLogos));
}

$handle = fopen(SETS_CSV, 'r');
$header = fgetcsv($handle);
$done = 0;
$skipped = 0;
$failed = 0;
$recovered = 0;
$backfilled = 0;
$noLogo = [];     // groupId => [categoryId, era]
$eraLogo = [];        // era => the logo that stands in for it
$eraCandidates = [];  // era => every set in it that has a logo

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
    if (isset($hiresLogos[$groupId])) {
        $logo = $hiresLogos[$groupId];
    }
    // Collect every candidate; eraFaceLogo() picks the base set from them once
    // the whole CSV has been read.
    if ($logo !== '') {
        $eraCandidates[$era][] = ['name' => $name, 'logo' => $logo];
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
foreach ($eraCandidates as $era => $candidates) {
    $face = eraFaceLogo((string) $era, $candidates);
    if ($face !== null) {
        $eraLogo[$era] = $face;
    }
}

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

/**
 * Japanese sets, from their own committed logo cache.
 *
 * They cannot ride the loop above: that one is driven by the Western groups CSV
 * and resolves era with the Western resolver. pokemontcg.io carries no Japanese
 * sets at all, so these come from the Bulbagarden Archives via
 * catalog/fetch-set-logos-jp.php, keyed on group id - the same join the Western
 * pass uses, so a TCGplayer rename cannot orphan the artwork.
 *
 * The wiki has a logo for 103 of the 454 groups; the rest are decks, starter
 * sets and half decks that were never given one. Those inherit their BLOCK's
 * logo, dimmed, exactly as the Western sets do - so the set browser is complete
 * rather than a grid with holes in it.
 */
$japanese = 0;
$japaneseFallback = 0;
$japanesePlate = 0;
if (is_readable(LOGOS_JP_CSV) && is_readable(GROUPS_JP_CSV)) {
    $jpLogos = [];
    $fh = fopen(LOGOS_JP_CSV, 'r');
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) >= 3 && trim((string) $r[2]) !== '') {
            $jpLogos[(int) $r[0]] = (string) $r[2];
        }
    }
    fclose($fh);
    line('Japanese logos loaded: ' . count($jpLogos));

    /**
     * A Japanese set may not wear a Western set's logo.
     *
     * The Japanese resolver falls back to matching on the plain set name, and
     * plenty of names exist in both regions - Black Bolt, White Flare, Battle
     * Academy, Gym Challenge - so it happily returned the ENGLISH file. The
     * English artwork on a Japanese set is worse than no artwork: it asserts
     * something false about the card in the box. Anything already claimed by a
     * Western set is dropped here and the block placeholder takes over.
     */
    $westernUrls = [];
    foreach ($extraLogos as $url) {
        $westernUrls[$url] = true;
    }
    foreach ($eraCandidates as $candidates) {
        foreach ($candidates as $candidate) {
            $westernUrls[$candidate['logo']] = true;
        }
    }
    $collisions = 0;
    foreach ($jpLogos as $groupId => $url) {
        if (isset($westernUrls[$url])) {
            unset($jpLogos[$groupId]);
            ++$collisions;
        }
    }
    if ($collisions > 0) {
        line("dropped $collisions Japanese logos that were Western files");
    }

    // Every group with its block and release date, oldest first - the earliest
    // set in a block is its base set, and the right face for the whole block.
    $jpSets = [];
    $fh = fopen(GROUPS_JP_CSV, 'r');
    $jpHeader = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $g = array_combine($jpHeader, array_pad($r, count($jpHeader), ''));
        $jpSets[] = [
            'group' => (int) $g['group_id'],
            'name' => (string) $g['name'],
            'era' => resolveEraJp((string) $g['name'], (string) $g['abbreviation'], (string) $g['published_on']),
            'published' => (string) $g['published_on'],
        ];
    }
    fclose($fh);
    usort($jpSets, static fn ($a, $b) => strcmp($a['published'], $b['published']));

    $jpEraLogo = [];
    $jpCandidates = [];
    foreach ($jpSets as $set) {
        $face = $hiresLogos[$set['group']] ?? $jpLogos[$set['group']] ?? null;
        if ($face !== null) {
            $jpCandidates[$set['era']][] = ['name' => $set['name'], 'logo' => $face];
        }
    }

    foreach ($jpCandidates as $era => $candidates) {
        $face = eraFaceLogo((string) $era, $candidates);
        if ($face !== null) {
            $jpEraLogo[$era] = $face;
        }
    }

    foreach ($jpSets as $set) {
        $categoryId = (int) Db::getInstance()->getValue(
            'SELECT id_category FROM ' . _DB_PREFIX_ . 'tcg_group_category WHERE group_id = ' . $set['group']
        );
        if (!$categoryId) {
            continue;
        }
        if (!FORCE && file_exists(_PS_CAT_IMG_DIR_ . $categoryId . '.jpg')) {
            continue;
        }

        $own = $hiresLogos[$set['group']] ?? $jpLogos[$set['group']] ?? null;
        if ($own !== null) {
            if (writeCategoryImage($categoryId, $own)) {
                ++$japanese;
            }
            continue;
        }
        /**
         * Third rung: the WESTERN era of the same generation.
         *
         * Whole early blocks - Base, Neo, VS, e-Card, ADV, PCG - have no logo on
         * the wiki for any of their sets, so a block-level fallback has nothing
         * to offer and 137 sets rendered blank. The generation blocks are the
         * same brand on both sides of the Pacific, which is what makes this a
         * borrow rather than an invention; the two blocks Japan alone ever had
         * (ADV, PCG) map onto the Western era that ran at the same time.
         */
        $western = JP_ERA_TO_WESTERN[$set['era']] ?? $set['era'];
        $inherited = $jpEraLogo[$set['era']] ?? $eraLogo[$western] ?? null;
        if ($inherited === null) {
            ++$japanesePlate;
            continue;
        }
        if (writeCategoryImage($categoryId, $inherited, true)) {
            ++$japaneseFallback;
        }
    }
}
line("Japanese set logos: $japanese (block-fallback: $japaneseFallback, no artwork: $japanesePlate)");

Tools::clearAllCache();
