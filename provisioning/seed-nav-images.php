<?php
/**
 * Gives the navigation and the homepage tiles real artwork to sit on.
 *
 * "Singles", "Sealed", "Sword & Shield" as bare words on a dark rectangle tell a
 * shopper nothing they did not already know from the label. What they want is to
 * recognise the thing: the era's logo, a booster wrapper, a card.
 *
 * Nothing here is drawn or invented. Every image is one already in the catalogue,
 * fetched from TCGplayer, pokemontcg.io or the Bulbagarden Archives:
 *
 *   an era        -> the logo of its most recent set that has one
 *   any other     -> the cover photo of the best card or product filed under it
 *   nothing yet   -> whatever its parent resolved to
 *
 * That last rule is the important one. Graded has no stock, so it inherits, and the
 * day a slab is listed the tile starts showing that slab without anyone editing
 * anything.
 *
 *   make nav-images
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/cutout.php';

/** Where the generated backgrounds live, served straight from /img. */
const NAV_IMG_DIR = _PS_IMG_DIR_ . 'nav/';
/**
 * Two shapes, because the menu and the tiles frame their artwork differently.
 *
 * A menu row is a wide, short strip; a tile is close to landscape. Rendering both
 * from one square-ish canvas is what made the dropdown look ragged - a wide logo
 * filled the row while a compact one floated in the middle at half the size.
 */
const NAV_LOGO_WIDTH = 900;
const NAV_LOGO_HEIGHT = 320;
const NAV_TILE_WIDTH = 900;
const NAV_TILE_HEIGHT = 600;

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Navigation artwork\033[0m\n";

$db = Db::getInstance();

if (!is_dir(NAV_IMG_DIR) && !mkdir(NAV_IMG_DIR, 0775, true) && !is_dir(NAV_IMG_DIR)) {
    warn('could not create ' . NAV_IMG_DIR);
    exit(1);
}

/**
 * A photograph dropped in by hand for a category that cannot source its own.
 *
 * Graded is the case this exists for. It holds no stock, and there is no freely
 * licensed photograph of a graded slab to fall back on - Wikimedia Commons and
 * Openverse both return nothing usable, and a product shot lifted off a
 * marketplace is not ours to publish. Drop a photo of one of your own slabs at
 * provisioning/assets/graded.jpg and it is used from then on.
 *
 * Matched on the English category name, lowercased: "Graded" -> assets/graded.jpg.
 */
function suppliedArtwork(string $categoryName): ?string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $categoryName), '-'));
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
        $candidate = __DIR__ . '/assets/' . $slug . '.' . $extension;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The supplied grading frame, with a real card composited into its window.
 *
 * A slab is a card inside a holder, so a photograph of an EMPTY holder is the one
 * asset that can be supplied once and stay true: the card in it comes from stock and
 * changes as stock does. Save the frame to provisioning/assets/graded-frame.webp
 * (png/jpg also accepted) with the card window left TRANSPARENT.
 *
 * The window is measured rather than configured, so re-shooting or rescaling the
 * frame does not silently misalign the card.
 */
function gradedFramePath(): ?string
{
    foreach (['webp', 'png', 'jpg', 'jpeg'] as $extension) {
        $candidate = __DIR__ . '/assets/graded-frame.' . $extension;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The longest run of transparent pixels along one axis.
 *
 * Longest RUN, not first-to-last: the frame's own outer edge is transparent too, so
 * taking the outermost transparent pixels returned the whole image. The window is
 * the one long uninterrupted stretch in the middle.
 *
 * @return array{0:int, 1:int} start and end index, inclusive
 */
function longestTransparentRun(callable $isTransparent, int $length): array
{
    $bestStart = 0;
    $bestLength = 0;
    $start = null;

    for ($i = 0; $i <= $length; ++$i) {
        if ($i < $length && $isTransparent($i)) {
            $start ??= $i;
            continue;
        }
        if ($start !== null && $i - $start > $bestLength) {
            $bestLength = $i - $start;
            $bestStart = $start;
        }
        $start = null;
    }

    return [$bestStart, $bestStart + $bestLength - 1];
}

function gradedArtwork(?string $cardPath): ?string
{
    $frame = gradedFramePath();
    if ($frame === null || $cardPath === null || !is_file($cardPath)) {
        return null;
    }

    $slab = cutoutLoad($frame);
    $card = cutoutLoad($cardPath);
    if ($slab === null || $card === null) {
        return null;
    }

    $frameW = imagesx($slab);
    $frameH = imagesy($slab);
    $transparent = static fn (int $x, int $y): bool => ((imagecolorat($slab, $x, $y) >> 24) & 0x7F) > 100;

    // Measured across the lower half, well clear of the grade label.
    $probeY = (int) ($frameH * 0.6);
    [$left, $right] = longestTransparentRun(static fn (int $x) => $transparent($x, $probeY), $frameW);
    $probeX = (int) (($left + $right) / 2);
    [$top, $bottom] = longestTransparentRun(static fn (int $y) => $transparent($probeX, $y), $frameH);

    $windowW = $right - $left + 1;
    $windowH = $bottom - $top + 1;
    if ($windowW < 40 || $windowH < 40) {
        imagedestroy($slab);
        imagedestroy($card);

        return null;
    }

    // Fill the window and centre; a slab window shares the card's 63x88 proportions,
    // so this crops by a pixel or two at most.
    $scale = max($windowW / imagesx($card), $windowH / imagesy($card));
    $cardW = (int) round(imagesx($card) * $scale);
    $cardH = (int) round(imagesy($card) * $scale);

    $canvas = cutoutCanvas($frameW, $frameH);

    /**
     * Card first, with blending OFF so its own alpha is copied verbatim.
     *
     * Blending a card onto a fully transparent canvas muddies the edge pixels, and
     * the edge pixels are exactly where a card's rounded corners live - the corners
     * would come out squared off against the holder.
     */
    imagealphablending($canvas, false);
    imagecopyresampled(
        $canvas, $card,
        $left + (int) (($windowW - $cardW) / 2),
        $top + (int) (($windowH - $cardH) / 2),
        0, 0, $cardW, $cardH, imagesx($card), imagesy($card)
    );

    // Frame last and blended, so its bevels and label sit OVER the card as in life.
    imagealphablending($canvas, true);
    imagecopy($canvas, $slab, 0, 0, 0, 0, $frameW, $frameH);

    imagedestroy($slab);
    imagedestroy($card);

    $out = sys_get_temp_dir() . '/graded-composite.png';
    $ok = cutoutSave($canvas, $out);
    imagedestroy($canvas);

    return $ok ? $out : null;
}

/** @return int[] this category and everything beneath it */
function descendants(int $categoryId): array
{
    $category = new Category($categoryId);
    if (!Validate::isLoadedObject($category)) {
        return [$categoryId];
    }
    $ids = [$categoryId];
    foreach ($category->getAllChildren(1) as $child) {
        $ids[] = (int) $child->id;
    }

    return $ids;
}

/**
 * The set logo to represent an era: its BASE SET.
 *
 * An era is named after its base set and branded by it - the Scarlet & Violet logo
 * IS the Scarlet & Violet Base Set logo. Picking the era's newest set instead put
 * "Crown Zenith" against Sword & Shield, "Black Bolt" against Scarlet & Violet and
 * "Pokémon Rumble" against Platinum: real logos, none of which name the era they
 * were standing in for.
 *
 * Where no set is literally called a base set - Neo, Gym, EX, e-Card - the era's
 * FIRST release is the one that defined its look, so the fallback is oldest rather
 * than newest.
 */
function eraArtwork(int $eraId): ?string
{
    $sets = Db::getInstance()->executeS(
        'SELECT c.id_category, en.name, g.published_on
           FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang en
                ON en.id_category = c.id_category AND en.id_lang = 1
           LEFT JOIN ' . _DB_PREFIX_ . 'tcg_group_category g ON g.id_category = c.id_category
          WHERE c.id_parent = ' . $eraId . ' AND c.active = 1
          -- Undated groups sort last, or a set with no release date would beat the
          -- era opener purely by being NULL.
          ORDER BY (g.published_on IS NULL) ASC, g.published_on ASC'
    ) ?: [];

    $onDisk = static function (array $row): ?string {
        $path = _PS_CAT_IMG_DIR_ . (int) $row['id_category'] . '.jpg';

        return file_exists($path) ? $path : null;
    };

    /**
     * Promo runs are never the face of an era.
     *
     * They carry the era's earliest dates - "Mega Evolution Promo" predates ME01,
     * "HGSS Promos" predates HeartGold SoulSilver - so ordering by release alone
     * hands the era to a promo sheet instead of its flagship. Energy subsets share
     * a release date with their base set and lose on the same grounds.
     */
    $flagship = array_values(array_filter(
        $sets,
        static fn ($row) => stripos((string) $row['name'], 'Promo') === false
            && stripos((string) $row['name'], 'Energies') === false
    ));
    $candidates = $flagship !== [] ? $flagship : $sets;

    /**
     * Among base sets, the SHORTEST name wins: "Base Set" is the era's face,
     * "Base Set (Shadowless)" is a print run of it and was winning on document
     * order alone.
     */
    $baseSets = array_values(array_filter(
        $candidates,
        static fn ($row) => stripos((string) $row['name'], 'Base Set') !== false
    ));
    usort($baseSets, static fn ($a, $b) => strlen((string) $a['name']) <=> strlen((string) $b['name']));

    foreach (array_merge($baseSets, $candidates) as $row) {
        $path = $onDisk($row);
        if ($path !== null) {
            return $path;
        }
    }

    return null;
}

/**
 * The cover photo of the best thing filed under a category.
 *
 * $exclude skips an image already spoken for elsewhere, so two tiles never show the
 * same picture: Graded would otherwise open with the identical card Singles uses.
 */
function productArtwork(int $categoryId, ?string $exclude = null): ?string
{
    $ids = descendants($categoryId);

    /**
     * Highest value first, but CASES last.
     *
     * TCGplayer shoots multi-packs as a stack with a composited "x6" badge burnt
     * into the photograph. Sorting on price alone put one of those at the top of the
     * Sealed tile, so the tile advertised a quantity marker for a product nobody was
     * looking at. A single sealed box is the cleaner picture and the more
     * representative one.
     */
    $rows = Db::getInstance()->executeS(
        'SELECT i.id_image
           FROM ' . _DB_PREFIX_ . 'category_product cp
           JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cp.id_product AND p.active = 1
           JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON pl.id_product = p.id_product AND pl.id_lang = 1
           JOIN ' . _DB_PREFIX_ . 'image i ON i.id_product = p.id_product AND i.cover = 1
           JOIN ' . _DB_PREFIX_ . 'stock_available sa
                ON sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0
          WHERE cp.id_category IN (' . implode(',', array_map('intval', $ids)) . ')
          ORDER BY (pl.name REGEXP "(Case|Bulk|Set of [0-9]+)") ASC, p.price DESC'
    ) ?: [];

    foreach ($rows as $row) {
        $image = new Image((int) $row['id_image']);
        if (!Validate::isLoadedObject($image)) {
            continue;
        }
        $path = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath() . '.jpg';
        if ($path !== $exclude && file_exists($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Renders one background: the artwork, scaled to fit, centred on transparency.
 *
 * Transparent rather than matted onto a panel colour, so the tile's own background
 * shows through and a future repaint cannot strand a baked-in backdrop - the exact
 * mistake the set logos carried until recently.
 */
function writeBackground(string $source, string $destination, bool $isLogo = false): bool
{
    $image = cutoutLoad($source);
    if ($image === null) {
        return false;
    }

    /**
     * Trim to the artwork before scaling.
     *
     * Set logos arrive with wildly different amounts of empty canvas around them,
     * and scaling the FILE meant scaling that padding too: "Mega Evolution" filled
     * its row while "Gym" and "Platinum" sat in the middle at a third the size. The
     * logos were never different sizes, their margins were. Cropping to the content
     * box first makes every one of them land at the same visual weight.
     */
    [$left, $top, $right, $bottom] = cutoutContentBox($image);
    $cropW = $right - $left + 1;
    $cropH = $bottom - $top + 1;
    if ($cropW > 8 && $cropH > 8 && ($cropW < imagesx($image) || $cropH < imagesy($image))) {
        $trimmed = cutoutCanvas($cropW, $cropH);
        imagealphablending($trimmed, false);
        imagecopy($trimmed, $image, 0, 0, $left, $top, $cropW, $cropH);
        imagedestroy($image);
        $image = $trimmed;
    }

    $width = $isLogo ? NAV_LOGO_WIDTH : NAV_TILE_WIDTH;
    $height = $isLogo ? NAV_LOGO_HEIGHT : NAV_TILE_HEIGHT;

    // A little breathing room, or a trimmed logo touches the edge of its row.
    $scaled = cutoutResize($image, (int) ($width * 0.94), (int) ($height * 0.94));
    imagedestroy($image);

    $canvas = cutoutCanvas($width, $height);
    imagealphablending($canvas, false);
    imagecopy(
        $canvas, $scaled,
        (int) (($width - imagesx($scaled)) / 2),
        (int) (($height - imagesy($scaled)) / 2),
        0, 0, imagesx($scaled), imagesy($scaled)
    );
    imagedestroy($scaled);

    $ok = cutoutSave($canvas, $destination);
    imagedestroy($canvas);

    return $ok;
}

// ---------------------------------------------------------------------------
$singlesId = (int) $db->getValue(
    'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
      WHERE cl.id_lang = 1 AND cl.name = "Singles"'
);

// Everything the menu and the homepage can show: the three sections and their
// children, depth 3 and 4.
$targets = $db->executeS(
    'SELECT c.id_category, c.id_parent, c.level_depth, cl.name
       FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
      WHERE c.active = 1 AND c.level_depth IN (3, 4)
      ORDER BY c.level_depth, c.id_category'
) ?: [];

$resolved = [];
$written = 0;
$inherited = 0;
$missing = [];

foreach ($targets as $row) {
    $id = (int) $row['id_category'];
    $parent = (int) $row['id_parent'];

    $source = suppliedArtwork((string) $row['name']);
    if ($source === null && strcasecmp((string) $row['name'], 'Graded') === 0) {
        /**
         * The best card Singles is NOT already showing.
         *
         * Both tiles pick "highest value in stock", so both opened with the same
         * Umbreon VMAX - the two tiles sitting side by side on the homepage showing
         * one card between them. Excluding Singles' pick hands Graded the next one
         * down, currently the Shadowless 1st Edition Charizard, which is the card
         * you would slab anyway.
         */
        $source = gradedArtwork(productArtwork($singlesId, $resolved[$singlesId] ?? null));
    }
    $source ??= $parent === $singlesId ? eraArtwork($id) : null;
    $source ??= productArtwork($id);

    /**
     * Nothing of its own: inherit.
     *
     * Graded is the live case - it holds no stock, so it and its five graders have
     * no photography to draw on, and Wikimedia Commons has no usable slab image
     * either. Rather than invent one, they borrow the game's own cards: a graded
     * Pokémon card is still a Pokémon card, and the moment a slab is listed
     * productArtwork() finds it and the borrowing stops.
     */
    if ($source === null) {
        $source = $resolved[$parent] ?? $resolved[$singlesId] ?? null;
        if ($source !== null) {
            ++$inherited;
        }
    }
    if ($source === null || !file_exists($source)) {
        $missing[] = (string) $row['name'];
        continue;
    }

    $resolved[$id] = $source;
    // Eras are logos in a menu row; the three sections are photographs on a tile.
    if (writeBackground($source, NAV_IMG_DIR . $id . '.png', $parent === $singlesId)) {
        ++$written;
    }
}

line("backgrounds written: $written (inherited from parent: $inherited)");
if ($missing !== []) {
    warn(count($missing) . ' with no artwork at all: ' . implode(', ', array_slice($missing, 0, 8)));
}

/**
 * The homepage "Sets" tile has no category of its own, so it gets the set
 * directory's own subject: a set logo. Base Set, because it is the one every
 * Pokémon collector recognises on sight.
 */
$baseSetId = (int) $db->getValue(
    'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
      WHERE cl.name = "Base Set" AND c.level_depth = 5'
);
$baseSetImage = _PS_CAT_IMG_DIR_ . $baseSetId . '.jpg';
if ($baseSetId && file_exists($baseSetImage) && writeBackground($baseSetImage, NAV_IMG_DIR . 'sets.png')) {
    line('sets tile: Base Set logo');
} else {
    warn('no artwork for the sets tile');
}

Tools::clearAllCache();
