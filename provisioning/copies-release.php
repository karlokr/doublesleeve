<?php
/**
 * Releases expired copy reservations.
 *
 * A cart holds a physical card for 30 minutes. Without this job an abandoned cart
 * would take a one-of-one Charizard off sale permanently - the single worst failure
 * mode of serialised inventory, because nothing surfaces it: the card simply stops
 * appearing available and no error is ever raised.
 *
 * Also verifies the invariant that makes the whole model trustworthy:
 *
 *     available + reserved == stock quantity   (per serialised SKU)
 *
 *   make copies-release
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Releasing expired copy reservations\033[0m\n";

$db = Db::getInstance();

$expired = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
      WHERE status = "reserved" AND reserved_until IS NOT NULL AND reserved_until < NOW()'
);

if ($expired > 0) {
    $db->execute(
        // is_chosen must reset too: a released copy that stays flagged as
        // "specifically chosen" is released LAST next time round, quietly skewing
        // FIFO rotation in favour of whatever an abandoned cart happened to pick.
        'UPDATE ' . _DB_PREFIX_ . 'card_copy
            SET status = "available", id_cart = NULL, reserved_until = NULL, is_chosen = 0
          WHERE status = "reserved" AND reserved_until IS NOT NULL AND reserved_until < NOW()'
    );
}
line("expired reservations released: $expired");

// Copy CHOICES are preferences, not holds, so nothing expires them - they simply
// accumulate once a cart is gone. Reservation moved to checkout, which made this
// table exist; this is what keeps it from growing forever.
$staleChoices = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy_choice ch
      LEFT JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = ch.id_cart
     WHERE c.id_cart IS NULL'
);
if ($staleChoices > 0) {
    $db->execute(
        'DELETE ch FROM ' . _DB_PREFIX_ . 'card_copy_choice ch
          LEFT JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = ch.id_cart
         WHERE c.id_cart IS NULL'
    );
}
line("stale copy choices cleared: $staleChoices");

// Reservations whose cart no longer exists (deleted / merged) would otherwise
// linger forever with a future expiry.
$orphaned = (int) $db->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy cc
      LEFT JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = cc.id_cart
      WHERE cc.status = "reserved" AND cc.id_cart IS NOT NULL AND c.id_cart IS NULL'
);
if ($orphaned > 0) {
    $db->execute(
        'UPDATE ' . _DB_PREFIX_ . 'card_copy cc
          LEFT JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = cc.id_cart
            SET cc.status = "available", cc.id_cart = NULL, cc.reserved_until = NULL
          WHERE cc.status = "reserved" AND cc.id_cart IS NOT NULL AND c.id_cart IS NULL'
    );
}
line("orphaned reservations released: $orphaned");

// ---------------------------------------------------------------------------
$drift = $db->executeS(
    'SELECT sa.id_product, sa.id_product_attribute, sa.quantity,
            (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy cc
              WHERE cc.id_product = sa.id_product
                AND cc.id_product_attribute = sa.id_product_attribute
                AND cc.status IN ("available","reserved")) AS live
       FROM ' . _DB_PREFIX_ . 'stock_available sa
      WHERE sa.id_product_attribute > 0
        AND EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'card_copy c2
                     WHERE c2.id_product = sa.id_product)
     HAVING live <> sa.quantity'
) ?: [];

if ($drift) {
    warn(count($drift) . ' SKUs where available+reserved != stock quantity:');
    foreach (array_slice($drift, 0, 5) as $row) {
        warn(sprintf('  product %d sku %d: stock %d, copies %d',
            (int) $row['id_product'], (int) $row['id_product_attribute'],
            (int) $row['quantity'], (int) $row['live']));
    }
} else {
    line('invariant holds: available + reserved == stock quantity');
}

$summary = $db->executeS(
    'SELECT status, COUNT(*) n FROM ' . _DB_PREFIX_ . 'card_copy GROUP BY status'
) ?: [];
foreach ($summary as $row) {
    line(sprintf('%-11s %d', $row['status'], (int) $row['n']));
}
