<?php
/**
 * Records which physical copies a cart line is asking for.
 *
 * The choice used to ride along on the add-to-cart form as `cc_copy_uid`, read
 * in hookActionCartSave. It never worked: that hook fires on the cart REFRESH
 * request, which carries only `ajax` and `action`, not on the request that
 * carries the form. So the field was posted, nothing read it, and every "choose
 * your exact card" selection was silently discarded at add-to-cart.
 *
 * Recording it explicitly removes the guesswork. The client posts the serials it
 * means once the line is actually in the cart, and this writes them - no
 * dependency on which of PrestaShop's cart hooks fires for a given flow, or on a
 * hidden input surviving a form serialisation.
 *
 * The choice is a PREFERENCE, not a hold: copies are reserved at checkout, and a
 * serial someone else has taken by then falls back to FIFO.
 */
declare(strict_types=1);

class Cryptocards_copiesChooseModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->id) {
            $this->respond(['ok' => false, 'reason' => 'no cart']);
        }

        $productId = (int) Tools::getValue('id_product', 0);
        $skuId = (int) Tools::getValue('id_product_attribute', 0);
        if ($productId <= 0) {
            $this->respond(['ok' => false, 'reason' => 'no product']);
        }

        $uids = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Tools::getValue('uids', ''))
        )));

        $db = Db::getInstance();

        /**
         * Only serials that really belong to this SKU and are still available.
         *
         * The list arrives from the browser, so it is checked rather than
         * trusted: a copy from another card, or one already sold, must not end
         * up recorded against this line.
         */
        $valid = [];
        foreach ($uids as $uid) {
            $ok = (int) $db->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
                  WHERE copy_uid = "' . pSQL((string) $uid) . '"
                    AND id_product = ' . $productId . '
                    AND id_product_attribute = ' . $skuId . '
                    AND status IN ("available", "reserved")'
            );
            if ($ok) {
                $valid[] = (string) $uid;
            }
        }

        /**
         * The list REPLACES what was stored for this line.
         *
         * Merging would leave a deselected serial recorded, and the line would
         * keep asking for a card the shopper explicitly dropped. An empty list is
         * therefore a valid instruction: it clears the line's choices.
         */
        $db->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'card_copy_choice
              WHERE id_cart = ' . (int) $cart->id . '
                AND id_product = ' . $productId . '
                AND id_product_attribute = ' . $skuId
        );
        foreach ($valid as $uid) {
            $db->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'card_copy_choice
                    (id_cart, id_product, id_product_attribute, copy_uid, date_add)
                 VALUES (' . (int) $cart->id . ', ' . $productId . ', ' . $skuId . ', "'
                 . pSQL($uid) . '", NOW())
                 ON DUPLICATE KEY UPDATE date_add = NOW()'
            );
        }

        $this->respond(['ok' => true, 'stored' => count($valid), 'uids' => $valid]);
    }

    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
