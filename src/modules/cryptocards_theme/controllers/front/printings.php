<?php
/**
 * Returns the separately sellable variants of a batch of products.
 *
 * A category listing renders one tile per PRODUCT, but a card that exists as both
 * 1st Edition Holofoil and Unlimited Holofoil is two different things to a buyer at
 * two very different prices. Showing one tile hides half the inventory.
 *
 * Rather than split them into separate products - which would give a card two
 * competing pages for the same search term - the listing is expanded client-side,
 * with every tile deep-linking to its own variant on the shared product page.
 *
 * WHAT COUNTS AS ITS OWN TILE
 *
 * Printing, always - that is what this started as. And every GRADED copy, because
 * a PSA 10 and a CGC 9 of one card are no more the same purchase than a 1st
 * Edition and an Unlimited are: different holder, different label, different
 * market, and each slab is a single serialised object. So the key is the printing
 * plus, for graded copies, the grader and the tier.
 *
 * Raw copies still collapse to one tile per printing showing the best condition in
 * stock. A shopper choosing between Near Mint and Lightly Played is picking a
 * quality of the same thing and does that on the product page; a shopper choosing
 * between a raw copy and a PSA 10 is choosing between two different products.
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
        foreach (['Printing', 'Condition', 'Grading'] as $englishName) {
            $groupIds[$englishName] = (int) Db::getInstance()->getValue(
                'SELECT id_attribute_group FROM ' . _DB_PREFIX_ . 'attribute_group_lang
                  WHERE id_lang = 1 AND name = "' . pSQL($englishName) . '"'
            );
        }
        // Grading is NOT required: a shop with no graded stock still expands by
        // printing, and joining on a group id of 0 would return nothing at all.
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

        // Graders ticked in the facet rail, for the same reason: filtering to CGC
        // and still being shown the PSA slab beside it undoes the filter one layer
        // above where it was applied.
        $gradings = array_values(array_filter(array_map(
            'trim',
            explode('|', (string) Tools::getValue('gradings', ''))
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
                    grading_lang.name AS grading,
                    grading_en.name AS grading_en,
                    -- The slab photo wired to this exact combination, so a graded
                    -- tile shows the holder rather than the loose card scan the
                    -- product cover carries.
                    (SELECT pai.id_image
                       FROM ' . _DB_PREFIX_ . 'product_attribute_image pai
                      WHERE pai.id_product_attribute = pa.id_product_attribute
                      ORDER BY pai.id_image DESC LIMIT 1) AS id_image,
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
               /**
                * Grading arrives through a derived table, joined LEFT.
                *
                * LEFT, because sealed products and any card predating the grading
                * axis carry no Grading attribute at all, and an inner join would
                * drop them from the listing along with their printings.
                *
                * Derived, because pac_* holds one row per ATTRIBUTE: joining it
                * directly and filtering the group in WHERE - which is what the
                * printing and condition joins above can safely do, being inner -
                * would either multiply every combination by its attribute count or
                * prune away the ungraded rows it is supposed to preserve.
                *
                * The English name rides along because "Ungraded" is what the
                * raw-versus-graded decision keys on, and that label is translated.
                */
               LEFT JOIN (
                    SELECT pac.id_product_attribute, pac.id_attribute
                      FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
                      JOIN ' . _DB_PREFIX_ . 'attribute a
                           ON a.id_attribute = pac.id_attribute
                           AND a.id_attribute_group = ' . (int) $groupIds['Grading'] . '
                    ) grading_map ON grading_map.id_product_attribute = pa.id_product_attribute
               LEFT JOIN ' . _DB_PREFIX_ . 'attribute_lang grading_lang
                    ON grading_lang.id_attribute = grading_map.id_attribute
                    AND grading_lang.id_lang = ' . $idLang . '
               LEFT JOIN ' . _DB_PREFIX_ . 'attribute_lang grading_en
                    ON grading_en.id_attribute = grading_map.id_attribute
                    AND grading_en.id_lang = 1
               LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product_attribute = pa.id_product_attribute
              WHERE pa.id_product IN (' . implode(',', $ids) . ')
                AND printing.id_attribute_group = ' . $groupIds['Printing'] . '
                AND grade.id_attribute_group = ' . $groupIds['Condition'] . ''
            . ($conditions ? ' AND condition_lang.name IN ("'
                . implode('","', array_map('pSQL', $conditions)) . '")' : '')
            . ($printings ? ' AND printing_lang.name IN ("'
                . implode('","', array_map('pSQL', $printings)) . '")' : '')
            . ($gradings ? ' AND grading_lang.name IN ("'
                . implode('","', array_map('pSQL', $gradings)) . '")' : '') . '
              ORDER BY pa.id_product, printing_lang.name, grade_rank, price'
        ) ?: [];

        /**
         * Collapse to one entry per (product, printing) for raw copies, and per
         * (product, printing, grader, tier) for graded ones.
         *
         * Rows arrive best-condition first, so for a raw printing the first
         * in-stock row wins and the worse conditions fold into it. A graded row
         * never folds into anything: its key carries the grader and the tier, so
         * a PSA 10, a PSA 9 and a CGC 9.5 of one card stand as three tiles.
         */
        $byProduct = [];
        foreach ($rows as $row) {
            if ((int) $row['quantity'] <= 0) {
                continue;
            }
            $productId = (string) (int) $row['id_product'];
            $printing = (string) $row['printing'];

            // Compared in ENGLISH: the storefront label is "Non gradée" in French,
            // so matching the localised name would treat every French raw copy as
            // graded and give each condition its own tile.
            $graded = ($row['grading_en'] ?? '') !== '' && $row['grading_en'] !== 'Ungraded';
            $key = $graded
                ? $printing . "\0" . $row['grading_en'] . "\0" . $row['grade']
                : $printing;

            if (isset($byProduct[$productId][$key])) {
                continue;
            }

            $byProduct[$productId][$key] = [
                'printing' => $printing,
                'grade' => (string) $row['grade'],
                'grading' => $graded ? (string) $row['grading'] : null,
                'idImage' => (int) $row['id_image'] ?: null,
                'price' => (float) $row['price'],
                /**
                 * Formatted HERE, where the locale and the currency are known.
                 *
                 * The tile used to be relabelled with a hand-built '$' + toFixed(2)
                 * in JS, which is the English format spelled out as a literal: on
                 * the French storefront an expanded tile read "$5899.96" beside an
                 * untouched one reading "1 739,35 $". Every graded copy is an
                 * expanded tile now, so that mismatch would be most of the page.
                 */
                'priceFormatted' => $this->context->currentLocale->formatPrice(
                    (float) $row['price'],
                    (string) $this->context->currency->iso_code
                ),
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

        /**
         * Returned when there is more than one variant to split the tile into -
         * or when the ONE variant carries its own photo.
         *
         * That second case is a graded listing with nothing to be expanded
         * against: filter the browser to CGC and a card whose only CGC copy is a
         * 10 has a single variant, so it was skipped, and the tile kept the
         * product cover - the loose card scan - while its own chips said CGC 10.
         * The slab is the product there; one variant does not make it optional.
         */
        $out = [];
        foreach ($byProduct as $productId => $variants) {
            if (count($variants) < 2 && !array_filter($variants, static fn ($v) => $v['idImage'] !== null)) {
                continue;
            }
            $out[$productId] = array_values($variants);
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
