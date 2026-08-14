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

    return $image === false ? null : $image;
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

    // The background is whatever the corners agree on. Sampling one corner would
    // be fooled by a logo that runs into it.
    $samples = [];
    foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
        $rgba = imagecolorat($canvas, $x, $y);
        $samples[] = [($rgba >> 16) & 255, ($rgba >> 8) & 255, $rgba & 255];
    }
    $background = [
        (int) round(array_sum(array_column($samples, 0)) / 4),
        (int) round(array_sum(array_column($samples, 1)) / 4),
        (int) round(array_sum(array_column($samples, 2)) / 4),
    ];

    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    $seen = [];
    $queue = [];
    for ($x = 0; $x < $width; ++$x) {
        $queue[] = [$x, 0];
        $queue[] = [$x, $height - 1];
    }
    for ($y = 0; $y < $height; ++$y) {
        $queue[] = [0, $y];
        $queue[] = [$width - 1, $y];
    }

    $cleared = 0;
    while ($queue) {
        [$x, $y] = array_pop($queue);
        if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
            continue;
        }
        $key = $y * $width + $x;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $rgba = imagecolorat($canvas, $x, $y);
        if ((($rgba >> 24) & 0x7F) > 100) {
            $queue[] = [$x + 1, $y]; $queue[] = [$x - 1, $y];
            $queue[] = [$x, $y + 1]; $queue[] = [$x, $y - 1];
            continue;
        }
        $distance = abs((($rgba >> 16) & 255) - $background[0])
            + abs((($rgba >> 8) & 255) - $background[1])
            + abs(($rgba & 255) - $background[2]);
        if ($distance > CUTOUT_TOLERANCE * 3) {
            continue;
        }

        imagesetpixel($canvas, $x, $y, $transparent);
        ++$cleared;
        $queue[] = [$x + 1, $y]; $queue[] = [$x - 1, $y];
        $queue[] = [$x, $y + 1]; $queue[] = [$x, $y - 1];
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
function cutoutResize($image, int $maxW, int $maxH)
{
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    $scale = min($maxW / $srcW, $maxH / $srcH);
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));

    $canvas = cutoutCanvas($newW, $newH);
    imagealphablending($canvas, false);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    return $canvas;
}
