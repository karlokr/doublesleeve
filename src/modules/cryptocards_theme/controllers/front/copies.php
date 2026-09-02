<?php
/**
 * Returns a page of photographed copies for one SKU.
 *
 * The product page ships only the first page of tiles (see COPY_PAGE): a card we
 * hold fifty photographed copies of carries two hundred photo URLs, and putting
 * all of them into every page load costs every visitor whether or not they open
 * the picker. The carousel asks for the next page as it is dragged toward the
 * end.
 *
 * Only PHOTOGRAPHED copies are returned, because only those become tiles. The
 * order matches copyContext() - by id_copy, which is intake order - so paging
 * cannot repeat or skip a copy as the shopper scrolls.
 */
declare(strict_types=1);

class Cryptocards_themeCopiesModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** Hard ceiling on one request, so a crafted limit cannot dump the table. */
    private const MAX_LIMIT = 60;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $productId = (int) Tools::getValue('id_product', 0);
        $skuId = (int) Tools::getValue('id_product_attribute', 0);
        $offset = max(0, (int) Tools::getValue('offset', 0));
        $limit = min(self::MAX_LIMIT, max(1, (int) Tools::getValue('limit', 24)));

        if ($productId <= 0) {
            $this->respond(['copies' => [], 'total' => 0]);
        }

        /**
         * Availability is checked here, not just at intake.
         *
         * A copy sold five minutes ago must stop being offered - the picker is a
         * list of cards you can actually buy, and its tiles set the order
         * quantity.
         */
        $where = 'c.id_product = ' . $productId . '
                  AND c.id_product_attribute = ' . $skuId . '
                  AND c.status = "available"
                  AND EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'card_copy_image ci
                               WHERE ci.id_copy = c.id_copy)';

        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy c WHERE ' . $where
        );

        $rows = Db::getInstance()->executeS(
            'SELECT c.id_copy, c.copy_uid, c.photo_policy
               FROM ' . _DB_PREFIX_ . 'card_copy c
              WHERE ' . $where . '
              ORDER BY c.id_copy
              LIMIT ' . $limit . ' OFFSET ' . $offset
        ) ?: [];

        if ($rows === []) {
            $this->respond(['copies' => [], 'total' => $total]);
        }

        $ids = implode(',', array_map(static fn ($row) => (int) $row['id_copy'], $rows));
        $photos = [];
        foreach (Db::getInstance()->executeS(
            'SELECT id_copy, filename, side, is_placeholder
               FROM ' . _DB_PREFIX_ . 'card_copy_image
              WHERE id_copy IN (' . $ids . ')
              ORDER BY id_copy, position'
        ) ?: [] as $row) {
            $photos[(int) $row['id_copy']][] = [
                'url' => __PS_BASE_URI__ . 'img/cc-copies/' . rawurlencode((string) $row['filename']),
                'side' => (string) $row['side'],
                'placeholder' => (bool) $row['is_placeholder'],
            ];
        }

        // Shaped exactly like copyContext()'s entries, so the client renders a
        // paged tile and an embedded one through the same code.
        $copies = [];
        foreach ($rows as $row) {
            $shots = $photos[(int) $row['id_copy']] ?? [];
            $copies[] = [
                'uid' => (string) $row['copy_uid'],
                'captured' => $shots !== [],
                'image' => $shots[0]['url'] ?? null,
                'photos' => $shots,
                'policy' => (string) $row['photo_policy'],
            ];
        }

        $this->respond(['copies' => $copies, 'total' => $total]);
    }

    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
