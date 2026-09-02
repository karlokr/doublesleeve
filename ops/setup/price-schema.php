<?php
/**
 * Schema + source mapping for the price engine.
 *
 * Idempotent: creates tables if absent, then reloads the source map from CSV.
 *
 *   make price-setup
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const SOURCES_CSV = '/provisioning/data/price-sources.csv';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Price engine schema\033[0m\n";

$db = Db::getInstance();

/**
 * Every decision the engine makes is written here - applied or not. This is the
 * audit trail that makes auto-pricing safe to switch on, and later it is the
 * dataset behind margin and velocity analysis.
 */
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'price_journal (
    id_journal      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_product      INT UNSIGNED NOT NULL,
    reference       VARCHAR(64) NOT NULL,
    run_id          VARCHAR(32) NOT NULL,
    old_price       DECIMAL(20,6) NOT NULL,
    proposed_price  DECIMAL(20,6) NOT NULL,
    applied_price   DECIMAL(20,6) DEFAULT NULL,
    market_cad      DECIMAL(20,6) NOT NULL,
    families        SMALLINT UNSIGNED NOT NULL,
    spread_pct      DECIMAL(10,4) NOT NULL,
    confidence      ENUM("high","medium","low") NOT NULL,
    action          VARCHAR(32) NOT NULL,
    reason          VARCHAR(255) NOT NULL DEFAULT "",
    sources         TEXT,
    date_add        DATETIME NOT NULL,
    PRIMARY KEY (id_journal),
    KEY idx_product (id_product),
    KEY idx_run (run_id),
    KEY idx_date (date_add)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
line('price_journal');

/** Per-product overrides: freeze a hand-priced item, or record what it cost you. */
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'price_policy (
    id_product      INT UNSIGNED NOT NULL,
    frozen          TINYINT(1) NOT NULL DEFAULT 0,
    cost_basis      DECIMAL(20,6) DEFAULT NULL,
    margin_override DECIMAL(10,4) DEFAULT NULL,
    note            VARCHAR(255) NOT NULL DEFAULT "",
    PRIMARY KEY (id_product)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
line('price_policy');

/** Maps our canonical reference to the external ids each source knows it by. */
// variant_key / tcgplayer_subtype pin WHICH PRINTING each product is. Without
// them a Base Set card resolves to several TCGplayer products sharing a number
// (Unlimited, Shadowless, 1st Edition) whose prices differ by 10x.
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'price_source_map (
    reference            VARCHAR(64) NOT NULL,
    kind                 VARCHAR(16) NOT NULL,
    pokemontcg_card_id   VARCHAR(64) NOT NULL DEFAULT "",
    tcgplayer_product_id INT UNSIGNED DEFAULT NULL,
    tcgplayer_group_id   INT UNSIGNED DEFAULT NULL,
    variant_key          VARCHAR(32) NOT NULL DEFAULT "",
    tcgplayer_subtype    VARCHAR(48) NOT NULL DEFAULT "",
    PRIMARY KEY (reference)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');

foreach ([['variant_key', 'VARCHAR(32) NOT NULL DEFAULT ""'],
          ['tcgplayer_subtype', 'VARCHAR(48) NOT NULL DEFAULT ""']] as [$column, $definition]) {
    $exists = $db->getValue(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = "' . _DB_PREFIX_ . 'price_source_map"
            AND column_name = "' . $column . '"'
    );
    if (!$exists) {
        $db->execute('ALTER TABLE ' . _DB_PREFIX_ . 'price_source_map ADD COLUMN ' . $column . ' ' . $definition);
    }
}
line('price_source_map');

/** Daily FX, so a source in USD or EUR can be normalised to shop currency. */
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'price_fx (
    pair      VARCHAR(8) NOT NULL,
    rate      DECIMAL(20,8) NOT NULL,
    observed  DATE NOT NULL,
    date_add  DATETIME NOT NULL,
    PRIMARY KEY (pair)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
line('price_fx');

// ---------------------------------------------------------------------------
// load the source map
// ---------------------------------------------------------------------------
if (!is_readable(SOURCES_CSV)) {
    warn('source CSV missing: ' . SOURCES_CSV);
    exit(1);
}

$handle = fopen(SOURCES_CSV, 'r');
$header = fgetcsv($handle);
$loaded = 0;
while (($row = fgetcsv($handle)) !== false) {
    $r = array_combine($header, array_pad($row, count($header), ''));
    if (trim((string) $r['reference']) === '') {
        continue;
    }
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_source_map
            (reference, kind, pokemontcg_card_id, tcgplayer_product_id, tcgplayer_group_id,
             variant_key, tcgplayer_subtype)
         VALUES ("' . pSQL($r['reference']) . '", "' . pSQL($r['kind']) . '",
                 "' . pSQL($r['pokemontcg_card_id']) . '",
                 ' . ($r['tcgplayer_product_id'] !== '' ? (int) $r['tcgplayer_product_id'] : 'NULL') . ',
                 ' . ($r['tcgplayer_group_id'] !== '' ? (int) $r['tcgplayer_group_id'] : 'NULL') . ',
                 "' . pSQL($r['variant_key'] ?? '') . '", "' . pSQL($r['tcgplayer_subtype'] ?? '') . '")
         ON DUPLICATE KEY UPDATE
            kind = VALUES(kind),
            pokemontcg_card_id = VALUES(pokemontcg_card_id),
            tcgplayer_product_id = VALUES(tcgplayer_product_id),
            tcgplayer_group_id = VALUES(tcgplayer_group_id),
            variant_key = VALUES(variant_key),
            tcgplayer_subtype = VALUES(tcgplayer_subtype)'
    );
    ++$loaded;
}
fclose($handle);
line("source map rows: $loaded");

/**
 * Guard against reference collisions.
 *
 * TCGplayer keeps parallel print-run groups that share card names AND numbers -
 * "Base Set" and "Base Set (Shadowless)" both contain Charizard 004/102. A
 * reference built from set code + number alone would collide between them, and
 * since the source map is keyed on reference the second card would silently
 * inherit the first one's prices. Use the TCGplayer group abbreviation (BS vs
 * BSS), which is unique per group, when seeding new sets.
 */
$collisions = $db->executeS(
    'SELECT reference, COUNT(*) n FROM ' . _DB_PREFIX_ . 'product
      WHERE reference <> "" GROUP BY reference HAVING n > 1'
) ?: [];

if ($collisions) {
    warn(count($collisions) . ' DUPLICATE product references - these share pricing incorrectly:');
    foreach (array_slice($collisions, 0, 5) as $row) {
        warn('  ' . $row['reference'] . ' x' . $row['n']);
    }
} else {
    line('no duplicate product references');
}

$matched = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference'
);
$total = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product');
line("products with a price source: $matched / $total");
