<?php
/**
 * Deepens a SKU's stock so the copy carousel can be exercised for real.
 *
 * The picker pages its tiles in at 24 a time and the biggest SKU in the fixture
 * data held 8 photographed copies, so the paging path could only ever be tested
 * by lowering the page size. This gives a couple of marquee cards a realistic
 * depth instead.
 *
 * Stock and copies are raised TOGETHER. `COUNT(available copies) == quantity` is
 * an invariant the shop proves on every copies-init run, so inserting copy rows
 * without moving stock_available would leave the catalogue failing its own
 * check - and inserting stock without copies would offer cards that no serial
 * points at.
 *
 * Dev fixture, not a real intake path: real depth arrives one card at a time
 * through inventory/add-card.php.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/inventory/seed-copy-depth.php
 *   ... --qty=50   how deep to make each SKU (default 50)
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

/**
 * The SKUs to deepen, by combination reference.
 *
 * Both are cards with real artwork worth paging through: the Base Set Charizard
 * everyone recognises, and the "bubble" Mew ex whose full-art frame makes a
 * thumbnail row easy to read at a glance.
 */
const DEEPEN = [
    'PKM-BS-4-HOLO-NM',
    'PKM-PAF-232-HOLO-NM',
];

$quantity = 50;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--qty=')) {
        $quantity = max(1, (int) substr($arg, 6));
    }
}

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Deepening copy stock\033[0m\n";
line('target quantity per SKU: ' . $quantity);

$db = Db::getInstance();

foreach (DEEPEN as $reference) {
    $row = $db->getRow(
        'SELECT id_product, id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute
          WHERE reference = "' . pSQL($reference) . '"'
    );
    if (!$row) {
        warn("no combination for $reference");
        continue;
    }
    $productId = (int) $row['id_product'];
    $skuId = (int) $row['id_product_attribute'];

    $before = (int) $db->getValue(
        'SELECT quantity FROM ' . _DB_PREFIX_ . 'stock_available
          WHERE id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId
    );
    if ($before >= $quantity) {
        line(sprintf('%-24s already %d in stock', $reference, $before));
        continue;
    }

    /**
     * Through StockAvailable, not a raw UPDATE.
     *
     * It keeps the product-level total in step with the per-combination row;
     * writing the combination row alone leaves the two disagreeing, and the
     * product page reads the total.
     */
    StockAvailable::setQuantity($productId, $skuId, $quantity);
    line(sprintf('%-24s %d -> %d in stock', $reference, $before, $quantity));
}

/**
 * Copies are created by the schema pass, which is the only thing that knows how
 * to mint a collision-free serial and how to prove the invariant afterwards.
 */
line('now run: make copies-init && make seed-photos');
