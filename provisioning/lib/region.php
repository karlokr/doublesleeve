<?php
/**
 * Print regions - the one place that decides what they are called.
 *
 * A region is the print lineage a set belongs to, and it is a property of the SET,
 * never of a copy: every card in "Brilliant Stars" is Western, every card in
 * "Star Birth" is Japanese. That is why it is derived rather than authored, and why
 * it can be both a product feature and a category without the two ever disagreeing.
 *
 * Region is NOT language. A Western set is one release printed in English, French,
 * German, Italian and Spanish at shared collector numbers - one listing, several
 * language SKUs. Japanese is the only region where the two coincide, which is
 * exactly what makes them look like a single axis. Language lives on the
 * combination (see the Card Language group in setup.php); region lives here.
 *
 * WHY REGION IS A CATEGORY UNDER SINGLES BUT NOT UNDER SEALED OR GRADED
 *
 * The test is whether the child list would have to carry a region label if region
 * were only a facet. Under Singles the children are eras, and the era lists are
 * genuinely different per region - Japan never had the Wizards of the Coast era at
 * all, and its ADV/PCG/LEGEND blocks are not EX/HeartGold & SoulSilver by another
 * name. A flat era grid would need "EX (Western)" beside "ADV (Japanese)", which is
 * a tree level smeared into the names where nobody can navigate it.
 *
 * Under Sealed the children are product types and under Graded they are grading
 * companies. Japan has booster boxes, PSA grades Japanese cards: those lists are
 * identical across regions and need no labelling, so region stays a refinement on
 * the leaf. Keeping it out of the tree there is also what preserves "all booster
 * boxes, either region" as a real page - in a tree the both-view IS the parent, and
 * Sealed > Western > Booster Boxes would have no combined node above it.
 */
declare(strict_types=1);

/**
 * Region => French CATEGORY label.
 *
 * Plural feminine because these sit under "Cartes à l'unité" and agree with
 * "cartes". The Region FEATURE in setup.php uses the singular ("Occidentale")
 * because it agrees with "Région" instead. Both are correct in their own frame;
 * they are not a drift.
 */
const REGION_CATEGORY_FR = [
    'Western' => 'Occidentales',
    'Japanese' => 'Japonaises',
    'Chinese' => 'Chinoises',
];

/**
 * Which TCGplayer category each region's set list is imported from.
 *
 * 3 is "Pokemon", 85 is "Pokemon Japan". Chinese has no TCGplayer category and
 * will need another source, so it is absent rather than guessed at.
 */
const REGION_TCG_CATEGORY = [
    'Western' => 3,
    'Japanese' => 85,
];

/** Display order: biggest catalogue first, so the nav opens on the useful one. */
const REGION_ORDER = ['Western', 'Japanese', 'Chinese'];

function regionCategoryLabel(string $region): array
{
    return ['en' => $region, 'fr' => REGION_CATEGORY_FR[$region] ?? $region];
}
