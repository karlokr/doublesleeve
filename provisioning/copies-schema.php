<?php
/**
 * Serialised inventory: one row per physical card.
 *
 * Quantity-based stock says "we have 4 Near Mint copies". That cannot answer "which
 * one am I buying?", cannot carry a photo of the actual item, and cannot be scanned
 * at pick/pack. Every unit therefore becomes a `card_copy` with its own short code.
 *
 * Sealed product is deliberately NOT serialised - one factory-sealed box is
 * genuinely interchangeable with another, so it keeps plain quantity stock and
 * always shows the stock photo.
 *
 * Stock stays authoritative in PrestaShop: this backfills one copy per unit already
 * in stock_available, so `COUNT(available copies) == quantity` by construction, and
 * `make copies-verify` proves it stays that way.
 *
 *   make copies-init
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Serialised inventory\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'card_copy (
    id_copy              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    copy_uid             VARCHAR(16) NOT NULL,
    id_product           INT UNSIGNED NOT NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    status               ENUM("available","reserved","sold","returned","quarantined")
                         NOT NULL DEFAULT "available",
    id_image             INT UNSIGNED DEFAULT NULL,
    photo_state          ENUM("pending","captured") NOT NULL DEFAULT "pending",
    -- photo_state is a fact ("has this copy been shot yet"); photo_policy is the
    -- INTENT ("are we ever going to shoot it"). Bulk commons are stock_only on
    -- purpose, and the storefront has to say so rather than imply a photo is
    -- coming. Set per copy, but in practice uniform across a SKU.
    photo_policy         ENUM("per_copy","stock_only") NOT NULL DEFAULT "per_copy",
    cost_basis           DECIMAL(20,6) DEFAULT NULL,
    location             VARCHAR(64) NOT NULL DEFAULT "",
    is_chosen            TINYINT(1) NOT NULL DEFAULT 0,
    id_cart              INT UNSIGNED DEFAULT NULL,
    reserved_until       DATETIME DEFAULT NULL,
    id_order             INT UNSIGNED DEFAULT NULL,
    date_add             DATETIME NOT NULL,
    PRIMARY KEY (id_copy),
    UNIQUE KEY uniq_uid (copy_uid),
    KEY idx_sku (id_product, id_product_attribute, status),
    KEY idx_status (status)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
// Added after the first cut; existing installs need the column backfilled.
if (!(int) $db->getValue(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = "' . _DB_PREFIX_ . 'card_copy"
        AND column_name = "is_chosen"'
)) {
    $db->execute('ALTER TABLE ' . _DB_PREFIX_ . 'card_copy
                  ADD COLUMN is_chosen TINYINT(1) NOT NULL DEFAULT 0 AFTER photo_state');
}
if (!(int) $db->getValue(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = "' . _DB_PREFIX_ . 'card_copy"
        AND column_name = "photo_policy"'
)) {
    $db->execute('ALTER TABLE ' . _DB_PREFIX_ . 'card_copy
                  ADD COLUMN photo_policy ENUM("per_copy","stock_only")
                  NOT NULL DEFAULT "per_copy" AFTER photo_state');
}
line('card_copy table ready');

/**
 * A shopper's chosen serial, recorded WITHOUT reserving it.
 *
 * Reservation happens at checkout now, so "choose your exact card" can no longer
 * be expressed by flipping card_copy.status at add-to-cart. It is a preference on
 * the cart line: honoured at checkout if that copy is still available, FIFO
 * fallback if someone else claimed it first.
 */
$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'card_copy_choice (
    id_cart              INT UNSIGNED NOT NULL,
    id_product           INT UNSIGNED NOT NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    copy_uid             VARCHAR(16) NOT NULL,
    date_add             DATETIME NOT NULL,
    PRIMARY KEY (id_cart, id_product, id_product_attribute),
    KEY idx_uid (copy_uid)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
line('card_copy_choice table ready');

// The hold window is a setting, not a constant - changing it must not need a deploy.
if (Configuration::get('CC_RESERVATION_MINUTES') === false) {
    Configuration::updateValue('CC_RESERVATION_MINUTES', 30);
}
line('reservation window: ' . (int) Configuration::get('CC_RESERVATION_MINUTES') . ' minutes');

/**
 * Short, unambiguous code for the printed label. Crockford-style alphabet: no
 * I/L/O/U, so a human reading a scuffed label off a sleeve cannot confuse
 * characters, and it never accidentally spells anything.
 */
function copyUid(): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $uid = '';
    for ($i = 0; $i < 8; ++$i) {
        $uid .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $uid;
}

// ---------------------------------------------------------------------------
// which products are serialised?
// ---------------------------------------------------------------------------
// Everything under Pokémon → Sealed keeps plain quantity stock.
$sealedId = (int) $db->getValue(
    'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $defaultLang . '
      WHERE cl.name = "Sealed"'
);

$sealedProducts = [];
if ($sealedId) {
    $sealed = new Category($sealedId);
    $ids = [$sealedId];
    foreach ($sealed->getAllChildren($defaultLang) as $child) {
        $ids[] = (int) $child->id;
    }
    foreach ($db->executeS(
        'SELECT DISTINCT id_product FROM ' . _DB_PREFIX_ . 'category_product
          WHERE id_category IN (' . implode(',', array_map('intval', $ids)) . ')'
    ) ?: [] as $row) {
        $sealedProducts[(int) $row['id_product']] = true;
    }
}
line(count($sealedProducts) . ' sealed products excluded from serialisation');

// ---------------------------------------------------------------------------
// backfill one copy per unit in stock
// ---------------------------------------------------------------------------
$stock = $db->executeS(
    'SELECT sa.id_product, sa.id_product_attribute, sa.quantity
       FROM ' . _DB_PREFIX_ . 'stock_available sa
      WHERE sa.id_product_attribute > 0 AND sa.quantity > 0'
) ?: [];

$created = 0;
$alreadyHad = 0;

foreach ($stock as $row) {
    $productId = (int) $row['id_product'];
    if (isset($sealedProducts[$productId])) {
        continue;
    }
    $skuId = (int) $row['id_product_attribute'];
    $quantity = (int) $row['quantity'];

    $existing = (int) $db->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
          WHERE id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId . '
            AND status IN ("available","reserved")'
    );
    if ($existing >= $quantity) {
        $alreadyHad += $existing;
        continue;
    }

    for ($i = $existing; $i < $quantity; ++$i) {
        // Collision is astronomically unlikely but the column is UNIQUE, so retry.
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $uid = copyUid();
            $taken = (int) $db->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy WHERE copy_uid = "' . pSQL($uid) . '"'
            );
            if (!$taken) {
                break;
            }
        }
        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'card_copy
                (copy_uid, id_product, id_product_attribute, status, photo_state, date_add)
             VALUES ("' . pSQL($uid) . '", ' . $productId . ', ' . $skuId . ', "available", "pending", NOW())'
        );
        ++$created;
    }
}

line("copies created: $created (already serialised: $alreadyHad)");

// ---------------------------------------------------------------------------
// prove the invariant
// ---------------------------------------------------------------------------
$drift = $db->executeS(
    'SELECT sa.id_product, sa.id_product_attribute, sa.quantity,
            (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy cc
              WHERE cc.id_product = sa.id_product
                AND cc.id_product_attribute = sa.id_product_attribute
                AND cc.status = "available") AS copies
       FROM ' . _DB_PREFIX_ . 'stock_available sa
      WHERE sa.id_product_attribute > 0 AND sa.quantity > 0
        AND sa.id_product NOT IN (' . (count($sealedProducts) ? implode(',', array_map('intval', array_keys($sealedProducts))) : '0') . ')
     HAVING copies <> sa.quantity'
) ?: [];

if ($drift) {
    warn(count($drift) . ' SKUs where available copies != stock quantity');
} else {
    line('invariant holds: available copies == stock quantity for every serialised SKU');
}
