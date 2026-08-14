<?php
/**
 * Returns the sellable printings for a batch of products.
 *
 * A category listing renders one tile per PRODUCT, but a card that exists as both
 * 1st Edition Holofoil and Unlimited Holofoil is two different things to a buyer at
 * two very different prices. Showing one tile hides half the inventory.
 *
 * Rather than split them into separate products - which would give a card two
 * competing pages for the same search term - the listing is expanded client-side,
 * with every tile deep-linking to its own printing on the shared product page.
 */
declare(strict_types=1);

class Cryptocards_themePrintingsModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) Tools::getValue('ids', ''))))));

        if (!$ids || count($ids) > 100) {
            $this->respond([]);
        }

        $idLang = (int) $this->context->language->id;

        /**
         * Attribute groups are resolved by their ENGLISH name (id_lang 1) and then
         * used by ID.
         *
         * Joining on the group's name in the CURRENT language silently returned
         * nothing the moment the labels were translated - "Printing" is
         * "Impression" on the French storefront, so the join matched zero rows and
         * tile expansion died there without any error.
         */
        $groupIds = [];
        foreach (['Printing', 'Condition'] as $englishName) {
            $groupIds[$englishName] = (int) Db::getInstance()->getValue(
                'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
                  WHERE id_lang = 1 AND name = "' . pSQL($englishName) . '"'
            );
        }
        if (!$groupIds['Printing'] || !$groupIds['Condition']) {
            $this->ajaxRender(json_encode([]));

            return;
        }

        // Conditions ticked in the facet rail. When present, the tile must describe
        // the best condition WITHIN that filter - never one the shopper excluded.
        $conditions = array_values(array_filter(array_map(
            'trim',
            explode('|', (string) Tools::getValue('conditions', ''))
        )));

        // Printings ticked in the facet rail. Without this the expansion happily
        // re-added every printing a product has, so filtering to "Unlimited
        // Holofoil" still rendered 1st Edition tiles beside it - the filter was
        // applied to the product list and then undone one layer up.
        $printings = array_values(array_filter(array_map(
            'trim',
            explode('|', (string) Tools::getValue('printings', ''))
        )));

        /**
         * One row per combination, ordered so the BEST AVAILABLE CONDITION for each
         * printing comes first.
         *
         * Ordering by price instead would surface the cheapest — a Damaged copy —
         * which misrepresents the listing: a browser scanning tiles should see the
         * best card on offer for that printing, and land on it when they click.
         */
        $rows = Db::getInstance()->executeS(
            'SELECT pa.id_product,
                    pa.id_product_attribute,
                    p.price + pa.price AS price,
                    printing_lang.name AS printing,
                    condition_lang.name AS grade,
                    -- Rank by position, not by label: conditions are created
                    -- best-to-worst, so position IS the grade order and it
                    -- survives translation.
                    grade.position AS grade_rank,
                    IFNULL(sa.quantity, 0) AS quantity
               FROM ' . _DB_PREFIX_ . 'product_attribute pa
               JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product
               JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac_p
                    ON pac_p.id_product_attribute = pa.id_product_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute printing ON printing.id_attribute = pac_p.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang printing_lang
                    ON printing_lang.id_attribute = printing.id_attribute AND printing_lang.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac_c
                    ON pac_c.id_product_attribute = pa.id_product_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute grade ON grade.id_attribute = pac_c.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang condition_lang
                    ON condition_lang.id_attribute = grade.id_attribute AND condition_lang.id_lang = ' . $idLang . '
               LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product_attribute = pa.id_product_attribute
              WHERE pa.id_product IN (' . implode(',', $ids) . ')
                AND printing.id_attribute_group = ' . $groupIds['Printing'] . '
                AND grade.id_attribute_group = ' . $groupIds['Condition'] . ''
            . ($conditions ? ' AND condition_lang.name IN ("'
                . implode('","', array_map('pSQL', $conditions)) . '")' : '')
            . ($printings ? ' AND printing_lang.name IN ("'
                . implode('","', array_map('pSQL', $printings)) . '")' : '') . '
              ORDER BY pa.id_product, printing_lang.name, grade_rank, price'
        ) ?: [];

        // Collapse to one entry per (product, printing). Rows arrive best-condition
        // first, so the first in-stock row wins.
        $byProduct = [];
        foreach ($rows as $row) {
            if ((int) $row['quantity'] <= 0) {
                continue;
            }
            $productId = (string) (int) $row['id_product'];
            $printing = (string) $row['printing'];

            if (isset($byProduct[$productId][$printing])) {
                continue;
            }

            $byProduct[$productId][$printing] = [
                'printing' => $printing,
                'grade' => (string) $row['grade'],
                'price' => (float) $row['price'],
                'idpa' => (int) $row['id_product_attribute'],
                // Full canonical link for this combination, so the product page
                // opens on the printing that was clicked. Building it by hand from
                // bare attribute ids produced "#/28/31/52", which PrestaShop cannot
                // resolve and the chip parser cannot read.
                'url' => $this->combinationLink(
                    (int) $row['id_product'],
                    (int) $row['id_product_attribute'],
                    $idLang
                ),
            ];
        }

        $out = [];
        foreach ($byProduct as $productId => $printings) {
            // Only worth expanding when there is genuinely more than one.
            if (count($printings) < 2) {
                continue;
            }
            $out[$productId] = array_values($printings);
        }

        $this->respond($out);
    }

    /**
     * Canonical product URL for one combination.
     *
     * getProductLink() already appends the variant anchor when given an
     * id_product_attribute - building a second one by hand produced links with two
     * fragments stuck together, which resolved to neither printing.
     */
    private function combinationLink(int $productId, int $skuId, int $idLang): string
    {
        return $this->context->link->getProductLink($productId, null, null, null, $idLang, null, $skuId);
    }

    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
