<?php
/**
 * Seeds graded stock as COMBINATIONS on the cards' own listings.
 *
 * Grading is a copy-state axis (see the Grading group in setup.php): a PSA 9 Base
 * Set Charizard joins the raw copies on the Charizard listing, it does not get a
 * listing of its own. Each graded combination is quantity 1 - a slab is a
 * serialised single object - and carries its own photo: the card's scan
 * composited into the frame for THAT grader and THAT grade, so the graded SKU a
 * shopper selects shows the holder and the label they are actually being sent.
 *
 * Prices are REAL, from provisioning/data/graded-prices.json - PriceCharting's
 * per-grade price points (Ungraded / 7 / 8 / 9 / 9.5 / PSA 10), fetched at seed
 * time - converted at the same Bank of Canada rate as everything else. Never a
 * multiplier off the raw price: the whole reason people slab cards is that the
 * graded market prices copies, not cards.
 *
 * Idempotent: keyed on the combination reference.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/inventory/seed-graded.php
 *
 * Photographs a slab only as it CREATES it. Re-photographing existing stock -
 * after the frame artwork changes, say - is media/slab-photos.php, which covers
 * the whole catalogue rather than just this fixture list.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AdminKernel.php';
require_once __DIR__ . '/../lib/cutout.php';
// slabFramePath(), slabComposite() and attachSlabImage() live here so the
// maintenance pass in media/slab-photos.php shoots identical photos.
require_once __DIR__ . '/../lib/slab.php';
(new AdminKernel('prod', false))->boot();

const PRICES_JSON = '/provisioning/data/graded-prices.json';

/**
 * What actually gets slabbed, per card: [company, tier value, PriceCharting
 * price point, combination-reference suffix].
 *
 * Tier values are the Condition axis's labelled tiers - a PSA 10 is "10 Gem
 * Mint", a BGS or CGC 9.5 is "9.5 Gem Mint" - because the numeral alone does not
 * identify a market (see the Condition vocabulary in setup.php).
 *
 * Price points: PriceCharting's structured curve has one company-specific point
 * (PSA 10) and grader-agnostic bands for the rest; the 9.5 band IS the BGS/CGC
 * 9.5 market, so those tiers price off it. The tiers with NO structured source -
 * BGS 10 Pristine, 10 Black Label, CGC 10 Pristine - exist in the vocabulary but
 * are deliberately not stocked here: their comps are individual auctions, and a
 * multiplier would be an invented price wearing a real label. They get priced
 * per-slab when a real one is listed.
 *
 * The reference suffix is explicit because it must stay STABLE while tier labels
 * evolve - it is the idempotence key, and deriving it from a label that later
 * gained an epithet would re-create every slab.
 */
const SLABS = [
    'PKM-BS-4' => [['PSA', '9', 'Grade 9', 'PSA9'], ['PSA', '8', 'Grade 8', 'PSA8']],
    'PKM-EVS-215' => [['PSA', '10 Gem Mint', 'PSA 10', 'PSA10'], ['PSA', '9', 'Grade 9', 'PSA9'],
                      ['BGS', '9.5 Gem Mint', 'Grade 9.5', 'BGS9.5'],
                      ['TAG', '10 Gem Mint', 'PSA 10', 'TAG10']],
    'PKM-PAF-232' => [['PSA', '10 Gem Mint', 'PSA 10', 'PSA10'], ['CGC', '9.5 Gem Mint', 'Grade 9.5', 'CGC9.5'],
                      ['TAG', '9.5 Gem Mint', 'Grade 9.5', 'TAG9.5']],
    'PKMJP-23601-347190' => [['PSA', '10 Gem Mint', 'PSA 10', 'PSA10'], ['PSA', '9', 'Grade 9', 'PSA9']],
    'PKMJP-23601-349190' => [['PSA', '10 Gem Mint', 'PSA 10', 'PSA10'], ['CGC', '9.5 Gem Mint', 'Grade 9.5', 'CGC9.5']],
    /**
     * The only copy of this card in stock IS the slab - no raw combinations
     * exist. Priced from Collectr's live CGC 10 Gem Mint figure (CAD 71.08 on
     * 2026-08-14, = USD 51.00 at the BoC rate), which agrees with the recent
     * PriceCharting sold-comp cluster (49.99-55.68 USD through July 2026); the
     * all-time comp median sits lower only because early-supply sales drag it.
     */
    'PKMJP-24444-022021' => [['CGC', '10 Gem Mint', 'CGC 10', 'CGC10']],
];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Seeding graded stock\033[0m\n";

$db = Db::getInstance();
$shopId = (int) Context::getContext()->shop->id;
$prices = json_decode((string) @file_get_contents(PRICES_JSON), true) ?: [];

$usdCad = (float) $db->getValue('SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "USDCAD"');
if ($usdCad < 1.0) {
    warn('no USDCAD rate - run price-sync first');
    exit(1);
}

function attributeIdOf(string $group, string $value): ?int
{
    $id = (int) Db::getInstance()->getValue(
        'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
           JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = 1
          WHERE agl.name = "' . pSQL($group) . '" AND al.name = "' . pSQL($value) . '"'
    );

    return $id ?: null;
}

/** The attributes of the product's default (ungraded NM) combination, minus Grading and Condition. */
function carriedAttributes(int $productId, int $gradingGroup, int $conditionGroup): array
{
    $defaultCombo = (int) Db::getInstance()->getValue(
        'SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute
          WHERE id_product = ' . $productId . ' ORDER BY default_on DESC, id_product_attribute ASC'
    );
    $out = [];
    foreach (Db::getInstance()->executeS(
        'SELECT pac.id_attribute, a.id_attribute_group
           FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
           JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
          WHERE pac.id_product_attribute = ' . $defaultCombo
    ) ?: [] as $row) {
        if (!in_array((int) $row['id_attribute_group'], [$gradingGroup, $conditionGroup], true)) {
            $out[] = (int) $row['id_attribute'];
        }
    }

    return $out;
}

$gradingGroup = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang WHERE id_lang = 1 AND name = "Grading"'
);
$conditionGroup = (int) $db->getValue(
    'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang WHERE id_lang = 1 AND name = "Condition"'
);

$created = 0;
$skipped = 0;

foreach (SLABS as $reference => $slabs) {
    $productId = (int) $db->getValue(
        'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '"'
    );
    if (!$productId) {
        warn("no product for $reference");
        continue;
    }
    $curve = $prices[$reference] ?? [];
    if ($curve === []) {
        warn("no graded price curve for $reference");
        continue;
    }
    $product = new Product($productId);
    $basePrice = (float) $product->price;

    foreach ($slabs as [$grader, $grade, $pricePoint, $refSuffix]) {
        if (!isset($curve[$pricePoint])) {
            warn("$reference: no $pricePoint price");
            continue;
        }
        $comboRef = $reference . '-' . $refSuffix;
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute
              WHERE reference = "' . pSQL($comboRef) . '"'
        );
        if ($exists) {
            ++$skipped;
            continue;
        }

        $graderAttr = attributeIdOf('Grading', $grader);
        $gradeAttr = attributeIdOf('Condition', $grade);
        if (!$graderAttr || !$gradeAttr) {
            warn("$reference: missing attribute $grader / $grade");
            continue;
        }

        $slabCad = round($curve[$pricePoint] * $usdCad, 2);

        $combination = new Combination();
        $combination->id_product = $productId;
        $combination->price = round($slabCad - $basePrice, 2);
        $combination->reference = $comboRef;
        $combination->minimal_quantity = 1;
        $combination->add();
        $combination->setAttributes(array_merge(
            carriedAttributes($productId, $gradingGroup, $conditionGroup),
            [$graderAttr, $gradeAttr]
        ));

        // A slab is one physical object.
        StockAvailable::setQuantity($productId, (int) $combination->id, 1);

        /**
         * Composited per grade, not once per card: the frames differ by grader
         * and by grade, so reusing one image across a listing's slabs would put
         * a PSA 10 label on its PSA 9.
         */
        $framePath = slabFramePath($grader, $grade);
        if ($framePath === null) {
            warn("$reference: no slab frame for $grader $grade");
        } else {
            $composite = slabComposite($productId, $framePath);
            if ($composite !== null) {
                attachSlabImage($productId, $composite, (int) $combination->id, $shopId);
                @unlink($composite);
            }
        }

        ++$created;
        line(sprintf('%s %s %s  $%s CAD', $reference, $grader, $grade, number_format($slabCad, 2)));
    }
}

line("graded combinations created: $created (skipped $skipped)");

Product::flushPriceCache();
Tools::clearAllCache();
