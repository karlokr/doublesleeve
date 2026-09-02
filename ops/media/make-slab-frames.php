<?php
/**
 * Generates a slab frame for every grader and grade from the supplied templates.
 *
 * Each grading company holds cards in a visibly different slab, so each gets its
 * own template photograph (assets/slab-templates/) rather than a recoloured PSA
 * one - a buyer looking at a listing should see the holder they are actually
 * being sent. Within a company the holder never changes; only the tier name and
 * the numeral do, so every other grade is derived from that company's template
 * by swapping those two pieces of text.
 *
 * Company marks, barcodes and subgrade rows are never touched: they are part of
 * the holder, not part of the grade, and a label missing its barcode reads as a
 * counterfeit at a glance.
 *
 * Some labels are one grade ONLY and are copied verbatim rather than generated:
 * Beckett's gold Pristine and Black Label, and CGC's gold Pristine, all of which
 * exist at 10 and nowhere else.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/media/make-slab-frames.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/cutout.php';

const TEMPLATE_DIR = '/provisioning/assets/slab-templates/';
/** The provisioning mount is read-only, so output is staged here and copied out. */
const FRAME_DIR = '/tmp/slabs/';
const FONT = '/provisioning/assets/fonts/LiberationSans-Regular.ttf';
const FONT_BOLD = '/provisioning/assets/fonts/LiberationSans-Bold.ttf';

/**
 * One entry per label design, measured off its own template at 574x975.
 *
 *   tier / number  the two boxes the grade is written into. They are erased to
 *                  the full extent of the original text but the tier box stops
 *                  short of the numeral, so a long name like "GEM MINT" keeps
 *                  clear air around the grade instead of butting into it
 *   clean_x        a column of pure background in those rows, tiled across the
 *                  box to erase the old text - a flat fill would wipe out the
 *                  brushed metal of Beckett's labels and CGC's gradient
 *   ink            text colour, because TAG prints white on black
 *   bold           Beckett, CGC and TAG set their labels bold; PSA does not
 *   grades         the company's published scale for THIS label
 */
const DESIGNS = [
    'psa' => [
        'template' => 'psa-red.webp',
        'tier' => ['x1' => 74, 'y1' => 66, 'x2' => 322, 'y2' => 120],
        'number' => ['x1' => 396, 'y1' => 70, 'x2' => 506, 'y2' => 160],
        'ink' => [17, 17, 17],
        'bold' => false,
        'align' => 'left',
        'grades' => [
            '10' => 'GEM MT', '9' => 'MINT', '8' => 'NM-MT', '7' => 'NM', '6' => 'EX-MT',
            '5' => 'EX', '4' => 'VG-EX', '3' => 'VG', '2' => 'GOOD', '1.5' => 'FR', '1' => 'PR',
        ],
    ],
    'bgs' => [
        'template' => 'bgs-silver.webp',
        'tier' => ['x1' => 192, 'y1' => 86, 'x2' => 452, 'y2' => 146],
        'number' => ['x1' => 462, 'y1' => 78, 'x2' => 526, 'y2' => 155],
        'ink' => [20, 20, 20],
        'bold' => true,
        'align' => 'left',
        // Beckett's silver label. 10 is gold (Pristine) or black (Black Label),
        // both copied verbatim below, so the silver scale stops at 9.5.
        'grades' => [
            '9.5' => 'GEM MINT', '9' => 'MINT', '8.5' => 'NM-MT+', '8' => 'NM-MT',
            '7.5' => 'NM+', '7' => 'NM', '6' => 'EX-MT', '5' => 'EX',
            '4' => 'VG-EX', '3' => 'VG', '2' => 'GOOD', '1' => 'POOR',
        ],
    ],
    'cgc' => [
        'template' => 'cgc-white.webp',
        'tier' => ['x1' => 62, 'y1' => 86, 'x2' => 404, 'y2' => 144],
        'number' => ['x1' => 424, 'y1' => 78, 'x2' => 516, 'y2' => 168],
        'ink' => [17, 17, 17],
        'bold' => true,
        'align' => 'left',
        'grades' => [
            '10' => 'GEM MINT', '9.5' => 'MINT+', '9' => 'MINT', '8.5' => 'NM-MT+',
            '8' => 'NM-MT', '7.5' => 'NM+', '7' => 'NM', '6' => 'EX-MT',
            '5' => 'EX', '4' => 'VG-EX', '3' => 'VG', '2' => 'GOOD', '1' => 'POOR',
        ],
    ],
    'tag' => [
        'template' => 'tag-black.webp',
        'tier' => ['x1' => 68, 'y1' => 118, 'x2' => 390, 'y2' => 190],
        // Stops at 516: TAG's original numeral runs to ~514 and the white border
        // begins at 522, so this is the last column that erases the old grade
        // without eating the holder's frame.
        'number' => ['x1' => 412, 'y1' => 114, 'x2' => 516, 'y2' => 196],
        'ink' => [255, 255, 255],
        'bold' => true,
        'align' => 'left',
        'grades' => [
            '10' => 'PRISTINE', '9.5' => 'GEM MINT', '9' => 'MINT', '8.5' => 'NM-MT+',
            '8' => 'NM-MT', '7.5' => 'NM+', '7' => 'NM', '6' => 'EX-MT',
            '5' => 'EX', '4' => 'VG-EX', '3' => 'VG', '2' => 'GOOD', '1' => 'POOR',
        ],
    ],
];

/**
 * Labels that exist at exactly one grade, copied rather than generated.
 *
 * Beckett's gold Pristine is awarded only at 10; its Black Label only when all
 * four subgrades are 10 - the numerals are part of that label's design, not a
 * field. CGC's gold Pristine carries its own embossed "PRISTINE 10" badge.
 */
const VERBATIM = [
    'bgs-10_pristine' => 'bgs-gold.webp',
    'bgs-10_black_label' => 'bgs-black-label.webp',
    'cgc-10_pristine' => 'cgc-gold.webp',
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Slab frames\033[0m\n";

if (!is_dir(FRAME_DIR) && !@mkdir(FRAME_DIR, 0775, true) && !is_dir(FRAME_DIR)) {
    warn('cannot create ' . FRAME_DIR);
    exit(1);
}

/**
 * Erase a box by regrowing the label's own background across it.
 *
 * Row by row, so a vertical gradient survives and Beckett's horizontal brushed
 * grain stays continuous. Painting a flat colour instead left an obvious patch
 * on every textured label.
 */
function eraseBox($image, array $box): void
{
    /**
     * Blending OFF while erasing.
     *
     * TAG's label is not a black rectangle - it is TRANSPARENT, with only the
     * white line-art opaque, and the dark look comes from the page behind it.
     * Erasing there means writing transparent pixels, and with blending on a
     * transparent source composites to nothing at all: the old grade stayed put
     * and the new one was drawn straight over it. Replacing pixels outright is
     * what "erase" has to mean.
     */
    imagealphablending($image, false);

    /**
     * Sampled from each box's OWN edges, four pixels out.
     *
     * A pair of columns shared by both boxes read the label at the wrong place:
     * Beckett's silver is 155 grey beside the tier text and 210 beside the
     * numeral, so filling the number box from the tier box's neighbourhood
     * painted a pale block behind the grade. Every box sits in a gap of its own,
     * and four pixels clears the glyphs' anti-aliasing without reaching the
     * holder's border.
     */
    $left = $box['x1'] - 4;
    $right = $box['x2'] + 4;
    $span = max(1, $box['x2'] - $box['x1']);

    for ($y = $box['y1']; $y <= $box['y2']; ++$y) {
        /**
         * Interpolated between a clean sample either side of the box.
         *
         * These labels are not flat - Beckett's silver and CGC's white both run
         * a horizontal gradient - so filling with one sampled colour, even a
         * median one, painted a visibly lighter rectangle behind the text.
         * Blending between the two edges follows whatever gradient the label
         * has, and on TAG both edges are transparent, which is exactly what
         * erasing there has to write.
         */
        $a = imagecolorat($image, $left, $y);
        $b = imagecolorat($image, $right, $y);

        for ($x = $box['x1']; $x <= $box['x2']; ++$x) {
            $t = ($x - $box['x1']) / $span;
            $colour = imagecolorallocatealpha(
                $image,
                (int) round(((($a >> 16) & 255) * (1 - $t)) + ((($b >> 16) & 255) * $t)),
                (int) round(((($a >> 8) & 255) * (1 - $t)) + ((($b >> 8) & 255) * $t)),
                (int) round((($a & 255) * (1 - $t)) + (($b & 255) * $t)),
                (int) round(((($a >> 24) & 0x7F) * (1 - $t)) + ((($b >> 24) & 0x7F) * $t))
            );
            imagesetpixel($image, $x, $y, $colour);
        }
    }

    // Text is drawn blended, so its anti-aliased edges melt into the label.
    imagealphablending($image, true);
}

/** Draw text sized to the box, aligned within it. */
function drawFitted($image, string $text, array $box, int $colour, string $font, string $align = 'centre'): void
{
    $boxW = $box['x2'] - $box['x1'];
    $boxH = $box['y2'] - $box['y1'];

    $size = 8.0;
    for ($try = 8.0; $try <= 160.0; $try += 1.0) {
        $m = imagettfbbox($try, 0, $font, $text);
        if (($m[2] - $m[0]) > $boxW || ($m[1] - $m[7]) > $boxH) {
            break;
        }
        $size = $try;
    }

    /**
     * imagettftext() positions by BASELINE, not by the box: bbox index 7 is the
     * top (negative, above the baseline) and index 1 the bottom. Centring by
     * height rather than by that top edge draws the text a full line too high,
     * up over the border of the holder.
     */
    $m = imagettfbbox($size, 0, $font, $text);
    $w = $m[2] - $m[0];
    $h = $m[1] - $m[7];

    $x = $align === 'left'
        ? (int) round($box['x1'] - $m[0])
        : (int) round($box['x1'] + ($boxW - $w) / 2 - $m[0]);
    $y = (int) round($box['y1'] + ($boxH - $h) / 2 - $m[7]);

    imagettftext($image, $size, 0, $x, $y, $colour, $font, $text);
}

$written = 0;

foreach (DESIGNS as $grader => $design) {
    $source = TEMPLATE_DIR . $design['template'];
    if (!is_file($source)) {
        warn('missing template: ' . $design['template']);
        continue;
    }
    $font = $design['bold'] && is_file(FONT_BOLD) ? FONT_BOLD : FONT;

    foreach ($design['grades'] as $gradeKey => $tier) {
        // PHP turns numeric array keys into ints, so "10" arrives as 10.
        $grade = (string) $gradeKey;

        $frame = cutoutLoad($source);
        if ($frame === null) {
            warn('cannot read ' . $design['template']);
            break;
        }
        imagealphablending($frame, true);
        $ink = imagecolorallocate($frame, ...$design['ink']);

        eraseBox($frame, $design['tier']);
        eraseBox($frame, $design['number']);

        drawFitted($frame, $tier, $design['tier'], $ink, $font, $design['align']);
        drawFitted($frame, $grade, $design['number'], $ink, $font, 'centre');

        $slug = $grader . '-' . str_replace('.', '_', $grade);
        if (cutoutSave($frame, FRAME_DIR . $slug . '.png')) {
            ++$written;
        } else {
            warn("failed to write $slug");
        }
        imagedestroy($frame);
    }
    line(sprintf('%-4s %2d grades from %s', strtoupper($grader), count($design['grades']), $design['template']));
}

foreach (VERBATIM as $slug => $template) {
    $frame = cutoutLoad(TEMPLATE_DIR . $template);
    if ($frame === null) {
        warn('missing template: ' . $template);
        continue;
    }
    if (cutoutSave($frame, FRAME_DIR . $slug . '.png')) {
        ++$written;
        line("$slug (verbatim - single-grade label)");
    }
    imagedestroy($frame);
}

line("frames written: $written to " . FRAME_DIR);
line('copy to ops/assets/slabs/ to commit');
warn('No SGC or ACE template supplied - those graders have no frames yet.');
