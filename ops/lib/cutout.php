<?php
/**
 * Puts fetched artwork on a transparent background.
 *
 * Stock art arrives on whatever the source shot it on: TCGplayer photograph cards
 * on a white sweep, Bulbagarden serve logos as PNGs whose alpha we then threw away
 * by compositing onto a hardcoded panel colour. Either way the result is an opaque
 * rectangle, which reads as a box drawn around the product - and the moment the
 * theme's surface colour changes, that box no longer even matches the page it sits
 * on.
 *
 * Two shapes, because cards and logos are different problems:
 *
 *   cutoutCard()  a card is a known rounded rectangle. Crop to it and round the
 *                 corners geometrically, so every card is cut identically no
 *                 matter how clean the scan was.
 *   cutoutLogo()  a logo is an arbitrary silhouette. Its background is whatever
 *                 the corners are, flood-filled inwards with a tolerance.
 *
 * Everything is written as PNG. JPEG cannot carry an alpha channel at all, which
 * is why assets/card-back.jpg could never have had a transparent background.
 */
declare(strict_types=1);

/** Channel value above which a pixel counts as blank sweep. */
const CUTOUT_WHITE = 238;
/** Fraction of a line that must be non-blank before it counts as content. */
const CUTOUT_CONTENT_RATIO = 0.02;
/**
 * Corner radius as a fraction of card width.
 *
 * A Pokemon card is 63mm across with a ~3mm corner, so a shade under 5%.
 */
const CUTOUT_RADIUS_RATIO = 0.048;
/** How far a pixel may drift from the sampled background and still be background. */
const CUTOUT_TOLERANCE = 34;

/**
 * Outer edge of the feather band, as a summed RGB distance.
 *
 * Between CUTOUT_TOLERANCE*3 and this, a pixel is treated as a blend of logo and
 * background and fades rather than flipping. Wide enough to catch the two or
 * three pixels of anti-aliasing a scaled logo carries, tight enough not to eat
 * into flat artwork.
 */
const CUTOUT_FEATHER_MAX = 190;

/** @return resource|GdImage|null */
function cutoutLoad(string $path)
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }
    $image = match ($info['mime']) {
        'image/png' => @imagecreatefrompng($path),
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/gif' => @imagecreatefromgif($path),
        default => false,
    };

    if ($image === false) {
        return null;
    }

    /**
     * Palette images become truecolour before anything touches them.
     *
     * An indexed PNG carries ONE fully transparent palette slot - transparency
     * is all-or-nothing, so its edges are hard by construction and every later
     * blend, resize and feather works from stair-steps. Converting up front at
     * least stops the pipeline compounding it; a genuinely soft edge has to come
     * from a better source.
     */
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);

    return $image;
}

function cutoutSave($image, string $path): bool
{
    imagealphablending($image, false);
    imagesavealpha($image, true);

    return imagepng($image, $path, 6);
}

/** True when a pixel is blank sweep or already transparent. */
function cutoutIsBlank($image, int $x, int $y): bool
{
    $rgba = imagecolorat($image, $x, $y);
    if ((($rgba >> 24) & 0x7F) > 100) {
        return true;
    }

    return (($rgba >> 16) & 255) >= CUTOUT_WHITE
        && (($rgba >> 8) & 255) >= CUTOUT_WHITE
        && ($rgba & 255) >= CUTOUT_WHITE;
}

/**
 * The bounding box of real content, as [left, top, right, bottom].
 *
 * Scans inward a line at a time until it meets one carrying enough non-blank
 * pixels, so artwork is never cut into.
 */
function cutoutContentBox($image): array
{
    $width = imagesx($image);
    $height = imagesy($image);

    $rowBlank = static function (int $y) use ($image, $width): bool {
        $limit = (int) max(1, $width * CUTOUT_CONTENT_RATIO);
        $hits = 0;
        for ($x = 0; $x < $width; $x += 2) {
            if (!cutoutIsBlank($image, $x, $y) && ++$hits > $limit) {
                return false;
            }
        }

        return true;
    };
    $colBlank = static function (int $x) use ($image, $height): bool {
        $limit = (int) max(1, $height * CUTOUT_CONTENT_RATIO);
        $hits = 0;
        for ($y = 0; $y < $height; $y += 2) {
            if (!cutoutIsBlank($image, $x, $y) && ++$hits > $limit) {
                return false;
            }
        }

        return true;
    };

    $top = 0;
    while ($top < $height - 1 && $rowBlank($top)) { ++$top; }
    $bottom = $height - 1;
    while ($bottom > $top && $rowBlank($bottom)) { --$bottom; }
    $left = 0;
    while ($left < $width - 1 && $colBlank($left)) { ++$left; }
    $right = $width - 1;
    while ($right > $left && $colBlank($right)) { --$right; }

    return [$left, $top, $right, $bottom];
}

/** A transparent canvas of the given size. */
function cutoutCanvas(int $width, int $height)
{
    $canvas = imagecreatetruecolor($width, $height);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

    return $canvas;
}

/**
 * Crops to the card and rounds its corners, everything outside going transparent.
 *
 * The corner is computed rather than flood-filled: a geometric radius cuts every
 * card to the same silhouette, where a flood fill follows whatever the scanner
 * happened to leave and gives each card slightly different corners.
 *
 * @return resource|GdImage|null null when there is nothing worth cutting
 */
function cutoutCard($image)
{
    [$left, $top, $right, $bottom] = cutoutContentBox($image);
    $width = $right - $left + 1;
    $height = $bottom - $top + 1;
    if ($width < 40 || $height < 40) {
        return null;
    }

    $canvas = cutoutCanvas($width, $height);
    imagecopy($canvas, $image, 0, 0, $left, $top, $width, $height);

    $radius = (int) round($width * CUTOUT_RADIUS_RATIO);
    if ($radius < 2) {
        return $canvas;
    }

    // Four corner squares, each masked against its own quarter-circle. The edge is
    // anti-aliased, or a 700px scan shows visible stair-stepping on a dark page.
    $corners = [
        [0, 0, $radius, $radius],
        [$width - $radius, 0, $width - $radius - 1, $radius],
        [0, $height - $radius, $radius, $height - $radius - 1],
        [$width - $radius, $height - $radius, $width - $radius - 1, $height - $radius - 1],
    ];

    foreach ($corners as $index => [$originX, $originY, $centreX, $centreY]) {
        $cx = $index % 2 === 0 ? $radius : $width - $radius - 1;
        $cy = $index < 2 ? $radius : $height - $radius - 1;

        for ($y = $originY; $y < $originY + $radius; ++$y) {
            for ($x = $originX; $x < $originX + $radius; ++$x) {
                if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                    continue;
                }
                $distance = sqrt((($x - $cx) ** 2) + (($y - $cy) ** 2));
                if ($distance <= $radius - 0.5) {
                    continue;
                }

                $rgba = imagecolorat($canvas, $x, $y);
                $alpha = $distance >= $radius + 0.5
                    ? 127
                    : (int) round(127 * ($distance - ($radius - 0.5)));
                $alpha = max((($rgba >> 24) & 0x7F), min(127, $alpha));

                imagesetpixel($canvas, $x, $y, imagecolorallocatealpha(
                    $canvas,
                    ($rgba >> 16) & 255,
                    ($rgba >> 8) & 255,
                    $rgba & 255,
                    $alpha
                ));
            }
        }
    }

    return $canvas;
}

/**
 * Drops a logo's background by flooding inwards from the edges.
 *
 * A logo has no predictable silhouette, so geometry is no help - but its
 * background always touches the border, and anything the flood cannot reach is
 * part of the mark. Counters inside letters stay filled, which is correct: they
 * are not reachable from outside and painting them out would hollow the logo.
 *
 * @return resource|GdImage|null
 */
function cutoutLogo($image)
{
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width < 8 || $height < 8) {
        return null;
    }

    $canvas = cutoutCanvas($width, $height);
    imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

    /**
     * The background is whatever the four corners agree on.
     *
     * Each corner is walked DIAGONALLY INWARD to the first opaque pixel, because
     * a plate is not always flush with the edge: plenty of the wiki's logos are
     * transparent PNGs wrapping an opaque rectangle, and reading the literal
     * corner there samples the transparent margin instead of the plate that
     * actually needs removing. The flood already travels through transparency,
     * so once the sample is right the rest works unchanged.
     *
     * The walk stops a quarter of the way in. Past that it is no longer sampling
     * a border, it is guessing at artwork.
     */
    $reach = (int) max(1, round(min($width, $height) * 0.25));
    $corners = [[0, 0, 1, 1], [$width - 1, 0, -1, 1], [0, $height - 1, 1, -1], [$width - 1, $height - 1, -1, -1]];

    $samples = [];
    foreach ($corners as [$x, $y, $stepX, $stepY]) {
        for ($i = 0; $i < $reach; ++$i) {
            $rgba = imagecolorat($canvas, $x + $stepX * $i, $y + $stepY * $i);
            if ((($rgba >> 24) & 0x7F) <= 100) {
                $samples[] = [($rgba >> 16) & 255, ($rgba >> 8) & 255, $rgba & 255];
                continue 2;
            }
        }
    }

    /**
     * All four corners must find an opaque pixel, and they must AGREE.
     *
     * This is the safety rule, and both halves matter. imagecolorat() reports a
     * colour for a transparent pixel too - almost always black - so without the
     * first half a fully transparent image yields a black "background" and the
     * flood then erases every dark pixel it can reach. Without the second half,
     * walking inward on artwork that was already cut out samples the artwork and
     * erases that. A real plate gives four matching readings; a photograph or a
     * silhouette gives four different ones, and is left alone.
     */
    if (count($samples) < 4) {
        imagedestroy($canvas);

        return null;
    }
    $background = [
        (int) round(array_sum(array_column($samples, 0)) / 4),
        (int) round(array_sum(array_column($samples, 1)) / 4),
        (int) round(array_sum(array_column($samples, 2)) / 4),
    ];
    foreach ($samples as $sample) {
        $drift = abs($sample[0] - $background[0])
            + abs($sample[1] - $background[1])
            + abs($sample[2] - $background[2]);
        if ($drift > CUTOUT_TOLERANCE) {
            imagedestroy($canvas);

            return null;
        }
    }

    /**
     * Visited map as a byte string, and each pixel queued at most once.
     *
     * Both of these are about memory, and neither is a micro-optimisation: the
     * queue used to take a pixel per NEIGHBOUR, deduplicating only when it was
     * popped, so a large logo held millions of pending [x, y] arrays and a
     * parallel $seen array of int keys. Re-rendering the real catalogue - where
     * the upgraded logos run past 1500px - exhausted a 512MB limit outright.
     * One byte per pixel and a seen-check before pushing keeps it flat.
     */
    $seen = str_repeat("\0", $width * $height);
    $queue = [];
    $push = static function (int $x, int $y) use (&$queue, &$seen, $width, $height): void {
        if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
            return;
        }
        $key = $y * $width + $x;
        if ($seen[$key] !== "\0") {
            return;
        }
        $seen[$key] = "\1";
        $queue[] = $key;
    };

    for ($x = 0; $x < $width; ++$x) {
        $push($x, 0);
        $push($x, $height - 1);
    }
    for ($y = 0; $y < $height; ++$y) {
        $push(0, $y);
        $push($width - 1, $y);
    }

    $cleared = 0;
    while ($queue) {
        $key = array_pop($queue);
        $x = $key % $width;
        $y = intdiv($key, $width);

        $rgba = imagecolorat($canvas, $x, $y);
        if ((($rgba >> 24) & 0x7F) > 100) {
            $push($x + 1, $y); $push($x - 1, $y);
            $push($x, $y + 1); $push($x, $y - 1);
            continue;
        }
        $red = ($rgba >> 16) & 255;
        $green = ($rgba >> 8) & 255;
        $blue = $rgba & 255;
        $distance = abs($red - $background[0])
            + abs($green - $background[1])
            + abs($blue - $background[2]);
        if ($distance > CUTOUT_FEATHER_MAX) {
            continue;
        }

        /**
         * Graded alpha, not a binary cut.
         *
         * A logo's edge is anti-aliased: those pixels are a blend of ink and
         * background, so a yes/no threshold either keeps them fully opaque - a
         * halo of background colour ringing the logo - or drops them entirely,
         * leaving stair-steps. Both were plainly visible on the set tiles.
         * Pixels matching the background outright go fully transparent;
         * everything out to CUTOUT_FEATHER_MAX fades across the band, keeping
         * its OWN colour so the edge still blends.
         */
        if ($distance <= CUTOUT_TOLERANCE * 3) {
            $alpha = 127;
        } else {
            $span = CUTOUT_FEATHER_MAX - (CUTOUT_TOLERANCE * 3);
            $alpha = (int) round(127 * (CUTOUT_FEATHER_MAX - $distance) / max(1, $span));
            $alpha = max(0, min(127, $alpha));
        }

        if ($alpha > 0) {
            $colour = imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha);
            if ($colour !== false) {
                imagesetpixel($canvas, $x, $y, $colour);
            }
        }
        ++$cleared;

        // Only keep flooding OUTWARD through background; a feathered edge pixel
        // is the boundary, not a doorway into the artwork.
        if ($alpha === 127) {
            $push($x + 1, $y); $push($x - 1, $y);
            $push($x, $y + 1); $push($x, $y - 1);
        }
    }

    // Nothing removed means the source already had a transparent background, or
    // the logo fills the frame edge to edge. Either way, leave it be.
    if ($cleared === 0) {
        imagedestroy($canvas);

        return null;
    }

    return $canvas;
}

/** Alpha-preserving resample. ImageManager::resize flattens onto white. */
/**
 * @param float $maxScale how far the image may be ENLARGED, 1.0 meaning never.
 *
 * Product thumbnails keep the default: blowing a 300px scan up to an 800px image
 * type adds no detail, it just interpolates the pixels bigger, and that is what
 * made the larger set tiles look soft. Logo rows are the exception - they are
 * normalising many sources to one visual weight, and a 500px block logo left at
 * native size inside a 900px row simply reads as half the size of its neighbour.
 * A bounded enlargement is the lesser evil there.
 */
function cutoutResize($image, int $maxW, int $maxH, float $maxScale = 1.0)
{
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    $scale = min($maxW / $srcW, $maxH / $srcH, max(1.0, $maxScale));
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));

    $canvas = cutoutCanvas($newW, $newH);
    imagealphablending($canvas, false);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    return $canvas;
}
