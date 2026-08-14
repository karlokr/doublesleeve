<?php
/**
 * Summarises the most recent price run.
 *
 * This is the thing you actually read during the dry-run period: what the engine
 * would have done, where it hesitated, and which products it wants a human to
 * look at before it touches them.
 *
 *   make price-report
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

$db = Db::getInstance();

$runId = (string) $db->getValue(
    'SELECT run_id FROM ' . _DB_PREFIX_ . 'price_journal ORDER BY id_journal DESC'
);

if ($runId === '') {
    echo "\nNo price runs recorded yet. Run: make price-sync\n";

    return;
}

$meta = $db->getRow(
    'SELECT MIN(date_add) AS started, COUNT(*) AS rows_written
       FROM ' . _DB_PREFIX_ . 'price_journal WHERE run_id = "' . pSQL($runId) . '"'
);

echo "\n\033[1m== Price run $runId\033[0m  ({$meta['started']}, {$meta['rows_written']} products)\n\n";

// --- what happened ---------------------------------------------------------
$actions = $db->executeS(
    'SELECT action, COUNT(*) n, ROUND(AVG(spread_pct),1) avg_spread
       FROM ' . _DB_PREFIX_ . 'price_journal
      WHERE run_id = "' . pSQL($runId) . '"
      GROUP BY action ORDER BY n DESC'
) ?: [];

printf("  %-16s %6s  %s\n", 'ACTION', 'COUNT', 'AVG SPREAD');
foreach ($actions as $row) {
    printf("  %-16s %6d  %s%%\n", $row['action'], (int) $row['n'], $row['avg_spread']);
}

// --- confidence mix --------------------------------------------------------
echo "\n  Confidence:\n";
foreach ($db->executeS(
    'SELECT confidence, COUNT(*) n FROM ' . _DB_PREFIX_ . 'price_journal
      WHERE run_id = "' . pSQL($runId) . '" GROUP BY confidence'
) ?: [] as $row) {
    printf("    %-8s %d\n", $row['confidence'], (int) $row['n']);
}

// --- biggest proposed moves ------------------------------------------------
echo "\n  Largest proposed moves:\n";
$moves = $db->executeS(
    'SELECT j.reference, pl.name, j.old_price, j.proposed_price, j.confidence, j.action,
            ROUND(((j.proposed_price - j.old_price) / NULLIF(j.old_price,0)) * 100, 1) AS pct
       FROM ' . _DB_PREFIX_ . 'price_journal j
       JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = j.id_product AND pl.id_lang = 1
      WHERE j.run_id = "' . pSQL($runId) . '" AND j.old_price > 0
        AND j.action NOT IN ("unchanged","no_data")
      ORDER BY ABS((j.proposed_price - j.old_price) / j.old_price) DESC
      LIMIT 12'
) ?: [];

foreach ($moves as $row) {
    printf(
        "    %-34s %8.2f -> %8.2f  %+7.1f%%  %-8s %s\n",
        mb_substr((string) $row['name'], 0, 33),
        (float) $row['old_price'],
        (float) $row['proposed_price'],
        (float) $row['pct'],
        (string) $row['confidence'],
        (string) $row['action']
    );
}

// --- the review queue ------------------------------------------------------
$review = $db->executeS(
    'SELECT j.reference, pl.name, j.old_price, j.proposed_price, j.reason
       FROM ' . _DB_PREFIX_ . 'price_journal j
       JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = j.id_product AND pl.id_lang = 1
      WHERE j.run_id = "' . pSQL($runId) . '" AND j.action = "needs_review"
      ORDER BY ABS(j.proposed_price - j.old_price) DESC LIMIT 10'
) ?: [];

if ($review) {
    echo "\n  Needs review (engine will not touch these):\n";
    foreach ($review as $row) {
        printf(
            "    %-34s %8.2f -> %8.2f  %s\n",
            mb_substr((string) $row['name'], 0, 33),
            (float) $row['old_price'],
            (float) $row['proposed_price'],
            (string) $row['reason']
        );
    }
}

// --- exposure --------------------------------------------------------------
$net = $db->getRow(
    'SELECT ROUND(SUM(proposed_price - old_price), 2) AS delta,
            ROUND(SUM(old_price), 2) AS book
       FROM ' . _DB_PREFIX_ . 'price_journal
      WHERE run_id = "' . pSQL($runId) . '" AND action IN ("propose","clamped","applied")'
);

if ($net && $net['book'] !== null) {
    printf(
        "\n  Catalogue value of actionable rows: %s CAD -> change %s CAD\n",
        number_format((float) $net['book'], 2),
        number_format((float) $net['delta'], 2)
    );
}

echo "\n";
