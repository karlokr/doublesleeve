<?php
/**
 * Binds serialised card copies to carts and orders.
 *
 * RESERVATION HAPPENS AT CHECKOUT, NOT AT ADD-TO-CART.
 *
 * The first cut reserved a physical card the moment it entered a cart. That is
 * wrong for one-of-one stock: an abandoned cart took a four-figure card off sale
 * for the whole window while nobody was buying it. Carts are browsing; checkout is
 * intent, and only intent should hold inventory.
 *
 *   add to cart     -> nothing is held. Quantity is capped at available stock and
 *                      that is the only constraint, so any number of shoppers may
 *                      hold the same card in their carts at once.
 *   enter checkout  -> claim atomically for RESERVATION_MINUTES. First to reach
 *                      checkout wins; the loser is told and their line is reduced.
 *   order validated -> the claimed copies become `sold` and carry id_order.
 *   window expires  -> copies-release.php returns them to `available`.
 *
 * Invariant maintained throughout:
 *
 *     available + reserved == stock_available.quantity      (per SKU)
 *
 * PrestaShop only decrements stock at order validation, so a reserved copy is
 * still "in stock" as far as the catalogue is concerned - it is simply spoken for.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptocards_copies extends Module
{
    /** Configuration key for the hold window, in minutes. */
    public const RESERVATION_KEY = 'CC_RESERVATION_MINUTES';

    /** Used when the configuration value is missing or nonsensical. */
    public const RESERVATION_DEFAULT = 30;

    public function __construct()
    {
        $this->name = 'cryptocards_copies';
        $this->tab = 'checkout';
        $this->version = '2.0.0';
        $this->author = 'DoubleSleeve';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'DoubleSleeve serialised copies';
        $this->description = 'Reserves the specific physical card at checkout, through to the order.';
    }

    public function install(): bool
    {
        Configuration::updateValue(self::RESERVATION_KEY, self::RESERVATION_DEFAULT);

        return parent::install()
            // Fires from OrderController::bootstrap() - i.e. the customer has
            // entered checkout. This is where stock is actually claimed.
            && $this->registerHook('actionCheckoutRender')
            // Still needed, but only to remember a chosen serial and to drop holds
            // when a line leaves the cart. It reserves nothing.
            && $this->registerHook('actionCartSave')
            && $this->registerHook('actionValidateOrder');
    }

    /** Hold window in minutes, clamped to something sane. */
    public static function reservationMinutes(): int
    {
        $minutes = (int) Configuration::get(self::RESERVATION_KEY);

        return $minutes > 0 ? $minutes : self::RESERVATION_DEFAULT;
    }

    // -----------------------------------------------------------------------
    // cart - records preferences only, never holds stock
    // -----------------------------------------------------------------------
    public function hookActionCartSave(array $params): void
    {
        $cart = $params['cart'] ?? null;
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }
        $cartId = (int) $cart->id;

        // "Choose your exact card" is a PREFERENCE recorded against the cart line,
        // not a hold. It is honoured at checkout if the copy is still available and
        // falls back to FIFO if someone else got there first.
        $chosenUid = trim((string) Tools::getValue('cc_copy_uid', ''));
        if ($chosenUid !== '') {
            $this->rememberChoice($cartId, $chosenUid);
        }

        // If a line has left the cart or shrunk, drop any holds beyond what is
        // still wanted. Holds only exist post-checkout-entry, so this is usually a
        // no-op; it matters when someone edits their cart after being sent back.
        $this->releaseSurplus($cartId);
    }

    /** Store the shopper's chosen serial for this cart, without reserving it. */
    private function rememberChoice(int $cartId, string $copyUid): void
    {
        $db = Db::getInstance();

        $copy = $db->getRow(
            'SELECT id_product, id_product_attribute FROM ' . _DB_PREFIX_ . 'card_copy
              WHERE copy_uid = "' . pSQL($copyUid) . '"'
        );
        if (!$copy) {
            return;
        }

        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'card_copy_choice
                (id_cart, id_product, id_product_attribute, copy_uid, date_add)
             VALUES (' . $cartId . ', ' . (int) $copy['id_product'] . ', '
             . (int) $copy['id_product_attribute'] . ', "' . pSQL($copyUid) . '", NOW())
             ON DUPLICATE KEY UPDATE copy_uid = VALUES(copy_uid), date_add = NOW()'
        );
    }

    /** Release holds for quantities no longer in the cart. */
    private function releaseSurplus(int $cartId): void
    {
        $db = Db::getInstance();

        $wanted = [];
        foreach ($db->executeS(
            'SELECT id_product, id_product_attribute, SUM(quantity) AS quantity
               FROM ' . _DB_PREFIX_ . 'cart_product
              WHERE id_cart = ' . $cartId . '
              GROUP BY id_product, id_product_attribute'
        ) ?: [] as $line) {
            $wanted[(int) $line['id_product'] . ':' . (int) $line['id_product_attribute']]
                = (int) $line['quantity'];
        }

        foreach ($db->executeS(
            'SELECT id_product, id_product_attribute, COUNT(*) AS held
               FROM ' . _DB_PREFIX_ . 'card_copy
              WHERE id_cart = ' . $cartId . ' AND status = "reserved"
              GROUP BY id_product, id_product_attribute'
        ) ?: [] as $row) {
            $key = (int) $row['id_product'] . ':' . (int) $row['id_product_attribute'];
            $surplus = (int) $row['held'] - ($wanted[$key] ?? 0);
            if ($surplus > 0) {
                $this->release($cartId, (int) $row['id_product'], (int) $row['id_product_attribute'], $surplus);
            }
        }
    }

    // -----------------------------------------------------------------------
    // checkout - this is where stock is actually claimed
    // -----------------------------------------------------------------------
    public function hookActionCheckoutRender(array $params): void
    {
        $cart = $this->context->cart ?? null;
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }

        $shortfalls = $this->claimForCart((int) $cart->id);
        if ($shortfalls === []) {
            return;
        }

        // Reduce each line to what we could actually claim, so the customer cannot
        // pay for a card someone else is already buying. Doing this here means the
        // totals they see and agree to are the totals we can honour.
        $controller = $this->context->controller;
        foreach ($shortfalls as $shortfall) {
            $cart->updateQty(
                $shortfall['missing'],
                $shortfall['id_product'],
                $shortfall['id_product_attribute'],
                false,
                'down'
            );

            if (isset($controller->warning) && is_array($controller->warning)) {
                $controller->warning[] = $this->shortfallMessage($shortfall);
            }
        }
    }

    /**
     * Claims every cart line for this cart, atomically.
     *
     * @return array<int, array{id_product:int, id_product_attribute:int, wanted:int, claimed:int, missing:int}>
     *         one entry per line that could NOT be fully claimed
     */
    private function claimForCart(int $cartId): array
    {
        $db = Db::getInstance();
        $expiry = date('Y-m-d H:i:s', time() + (self::reservationMinutes() * 60));
        $shortfalls = [];

        $lines = $db->executeS(
            'SELECT id_product, id_product_attribute, SUM(quantity) AS quantity
               FROM ' . _DB_PREFIX_ . 'cart_product
              WHERE id_cart = ' . $cartId . '
              GROUP BY id_product, id_product_attribute'
        ) ?: [];

        foreach ($lines as $line) {
            $productId = (int) $line['id_product'];
            $skuId = (int) $line['id_product_attribute'];
            $wanted = (int) $line['quantity'];

            // Sealed product and anything not serialised has no copies at all;
            // PrestaShop's own stock check governs those.
            $serialised = (int) $db->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
                  WHERE id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId
            );
            if ($serialised === 0) {
                continue;
            }

            // Re-entering checkout must EXTEND an existing hold, not double-book it.
            $alreadyHeld = (int) $db->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
                  WHERE id_cart = ' . $cartId . ' AND status = "reserved"
                    AND id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId
            );
            if ($alreadyHeld > 0) {
                $db->execute(
                    'UPDATE ' . _DB_PREFIX_ . 'card_copy SET reserved_until = "' . pSQL($expiry) . '"
                      WHERE id_cart = ' . $cartId . ' AND status = "reserved"
                        AND id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId
                );
            }

            $shortfall = $wanted - $alreadyHeld;
            if ($shortfall <= 0) {
                continue;
            }

            $claimed = $alreadyHeld + $this->reserve(
                $cartId,
                $productId,
                $skuId,
                $shortfall,
                $expiry,
                $this->chosenUid($cartId, $productId, $skuId)
            );

            if ($claimed < $wanted) {
                $shortfalls[] = [
                    'id_product' => $productId,
                    'id_product_attribute' => $skuId,
                    'wanted' => $wanted,
                    'claimed' => $claimed,
                    'missing' => $wanted - $claimed,
                ];
            }
        }

        return $shortfalls;
    }

    /** The serial this cart asked for on this line, if any. */
    private function chosenUid(int $cartId, int $productId, int $skuId): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT copy_uid FROM ' . _DB_PREFIX_ . 'card_copy_choice
              WHERE id_cart = ' . $cartId . ' AND id_product = ' . $productId . '
                AND id_product_attribute = ' . $skuId
        );
    }

    /**
     * Reserves up to $count copies, honouring an explicitly chosen serial first.
     *
     * The UPDATE is the lock: it only matches rows still `available`, so two carts
     * racing for the last copy cannot both win - the loser gets 0 affected rows and
     * falls through to the next candidate.
     *
     * @return int how many were actually claimed
     */
    private function reserve(
        int $cartId,
        int $productId,
        int $skuId,
        int $count,
        string $expiry,
        string $chosenUid = ''
    ): int {
        $db = Db::getInstance();
        $remaining = $count;
        $claimed = 0;

        if ($chosenUid !== '') {
            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'card_copy
                    SET status = "reserved", is_chosen = 1, id_cart = ' . $cartId . ',
                        reserved_until = "' . pSQL($expiry) . '"
                  WHERE copy_uid = "' . pSQL($chosenUid) . '"
                    AND id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId . '
                    AND status = "available"'
            );
            if ($db->Affected_Rows() > 0) {
                --$remaining;
                ++$claimed;
            }
        }

        if ($remaining <= 0) {
            return $claimed;
        }

        // FIFO: oldest stock leaves first, which is also correct rotation. Fetch
        // more candidates than needed because others may claim some mid-loop.
        $candidates = $db->executeS(
            'SELECT id_copy FROM ' . _DB_PREFIX_ . 'card_copy
              WHERE id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId . '
                AND status = "available"
              ORDER BY id_copy
              LIMIT ' . (int) ($remaining * 3)
        ) ?: [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }
            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'card_copy
                    SET status = "reserved", is_chosen = 0, id_cart = ' . $cartId . ',
                        reserved_until = "' . pSQL($expiry) . '"
                  WHERE id_copy = ' . (int) $candidate['id_copy'] . ' AND status = "available"'
            );
            if ($db->Affected_Rows() > 0) {
                --$remaining;
                ++$claimed;
            }
        }

        return $claimed;
    }

    private function release(int $cartId, int $productId, int $skuId, int $count): void
    {
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'card_copy
                SET status = "available", is_chosen = 0, id_cart = NULL, reserved_until = NULL
              WHERE id_cart = ' . $cartId . ' AND status = "reserved"
                AND id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId . '
              -- an explicitly chosen card is released last: losing the copy you
              -- picked because you edited the quantity would be baffling.
              ORDER BY is_chosen ASC, id_copy DESC
              LIMIT ' . (int) $count
        );
    }

    /** Customer-facing explanation, in the active language. */
    private function shortfallMessage(array $shortfall): string
    {
        $idLang = (int) $this->context->language->id;
        $product = new Product($shortfall['id_product'], false, $idLang);
        $name = Validate::isLoadedObject($product) ? $product->name : '';

        $french = $this->isFrench();

        if ($shortfall['claimed'] === 0) {
            return $french
                ? sprintf(
                    'Désolé — « %s » vient d’être réservé par un autre client et a été retiré de votre panier. '
                    . 'Ces cartes sont uniques : la première personne à passer à la caisse l’obtient.',
                    $name
                )
                : sprintf(
                    'Sorry — "%s" was just reserved by another customer and has been removed from your cart. '
                    . 'These cards are one of a kind: the first person to reach checkout gets them.',
                    $name
                );
        }

        return $french
            ? sprintf(
                'Seulement %d exemplaire(s) de « %s » sont encore disponibles; votre panier a été ajusté.',
                $shortfall['claimed'],
                $name
            )
            : sprintf(
                'Only %d of "%s" %s still available, so your cart has been adjusted.',
                $shortfall['claimed'],
                $name,
                $shortfall['claimed'] === 1 ? 'is' : 'are'
            );
    }

    private function isFrench(): bool
    {
        $iso = strtolower((string) $this->context->language->iso_code);
        $locale = strtolower((string) ($this->context->language->locale ?? ''));

        // fr-CA installs under the iso code "qc", so the locale is checked too.
        return $iso === 'fr' || $iso === 'qc' || str_starts_with($locale, 'fr');
    }

    // -----------------------------------------------------------------------
    // order
    // -----------------------------------------------------------------------
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;
        $cart = $params['cart'] ?? null;
        if (!$order instanceof Order || !$cart instanceof Cart) {
            return;
        }

        // The physical cards this cart was holding are now sold, and stay tied to
        // the order so a return can re-shelf the exact copy.
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'card_copy
                SET status = "sold", id_order = ' . (int) $order->id . ', reserved_until = NULL
              WHERE id_cart = ' . (int) $cart->id . ' AND status = "reserved"'
        );

        // The choice rows have served their purpose.
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'card_copy_choice WHERE id_cart = ' . (int) $cart->id
        );
    }
}
