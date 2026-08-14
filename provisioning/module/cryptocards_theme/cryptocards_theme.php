<?php
/**
 * DoubleSleeve design system.
 *
 * Layers a complete visual identity over the Hummingbird theme rather than forking
 * it: the theme keeps receiving upstream updates, and everything DoubleSleeve-specific
 * lives in one versioned module.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptocards_theme extends Module
{
    public function __construct()
    {
        $this->name = 'cryptocards_theme';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'DoubleSleeve';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'DoubleSleeve design system';
        $this->description = 'Dark holo-foil visual identity for the DoubleSleeve storefront.';
    }

    /**
     * Depth the main menu is allowed to render.
     *
     * Depth 2 lands exactly on the useful line: Singles shows its 15 eras, Sealed
     * shows its 8 product types, Graded shows its 5 graders - and the 217 SETS,
     * which sit one level below an era, are excluded.
     *
     * The set taxonomy grows every few months and only a handful are ever in
     * stock; a hover panel is the wrong place for it. Sets are reached through the
     * "Browse Pokemon Sets" page, the faceted rail and search - which is how
     * TCGplayer and Troll and Toad do it.
     */
    /**
     * 3, not 2, because print region now sits between Singles and the eras: the
     * chain is Pokémon > Singles > Western > Sword & Shield. Pruning at 2 stopped
     * at the region and left the panel showing one word and nothing to click.
     * Sets - the 217-entry level below eras - are still cut.
     */
    private const MENU_MAX_DEPTH = 3;

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionMainMenuModifier');
    }

    /**
     * Prunes the main menu to MENU_MAX_DEPTH.
     *
     * ps_mainmenu renders Category::getNestedCategories() in full and offers no
     * depth setting, so before this the Pokemon panel listed all 15 eras and all
     * 217 sets - 260 links in a dropdown, of which 13 had stock. Pruning here
     * rather than in a template keeps Hummingbird unforked, and keeps the markup
     * out of the page entirely instead of hiding it with CSS.
     *
     * @param array{menu: array} $params passed by reference by ps_mainmenu
     */
    public function hookActionMainMenuModifier(array $params): void
    {
        if (!isset($params['menu']['children']) || !is_array($params['menu']['children'])) {
            return;
        }

        $params['menu']['children'] = $this->pruneMenu($params['menu']['children'], 0);
        $params['menu']['children'] = $this->pruneOutOfStock($params['menu']['children'], 0);
        $params['menu']['children'] = $this->rewriteGradedEntry($params['menu']['children']);
        $params['menu']['children'] = $this->addSetsLink($params['menu']['children']);
    }

    /**
     * Drops category entries nothing can be bought from.
     *
     * An era or region with no purchasable stock is a link to an empty page - a
     * shopper clicks "e-Card" and gets disappointment. Pruned at REGION level and
     * below only: the rail (Singles / Sealed / Graded) is the shop's shape and
     * stays put even when a section is momentarily empty, the levels beneath it
     * are just routes to stock.
     *
     * "Stocked" rolls up through the nested tree, so an era counts as stocked when
     * any set under it has a product with quantity, whether or not products are
     * associated with the era row itself.
     *
     * @param array<int, array> $nodes
     * @return array<int, array>
     */
    private function pruneOutOfStock(array $nodes, int $depth): array
    {
        $stocked = $this->stockedCategories();

        $walk = function (array $nodes, int $depth) use (&$walk, $stocked): array {
            $out = [];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    $out[] = $node;
                    continue;
                }
                if ($depth >= 2
                    && preg_match('/^category-(\d+)$/', (string) ($node['page_identifier'] ?? ''), $m)
                    && !isset($stocked[(int) $m[1]])) {
                    continue;
                }
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                $node['children'] = $walk($children, $depth + 1);
                $out[] = $node;
            }

            return $out;
        };

        return $walk($nodes, $depth);
    }

    /** @return array<int, true> category id => has purchasable stock somewhere beneath it */
    private function stockedCategories(): array
    {
        $direct = Db::getInstance()->executeS(
            'SELECT DISTINCT cp.id_category
               FROM ' . _DB_PREFIX_ . 'category_product cp
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.quantity > 0'
        ) ?: [];
        $directIds = array_column($direct, 'id_category');
        if ($directIds === []) {
            return [];
        }

        // Roll up: every ancestor of a stocked category is stocked.
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT anc.id_category
               FROM ' . _DB_PREFIX_ . 'category anc
               JOIN ' . _DB_PREFIX_ . 'category child
                    ON child.nleft >= anc.nleft AND child.nright <= anc.nright
              WHERE child.id_category IN (' . implode(',', array_map('intval', $directIds)) . ')'
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_category']] = true;
        }

        return $out;
    }

    /**
     * Points the Graded rail entry at the graded FILTER instead of the retired
     * category tree, with one child per grading company that has stock.
     *
     * Grading is a copy-state axis on the card's own listing, so "PSA" is not a
     * place - it is Singles filtered to Grading = PSA, and that is exactly the URL
     * each child carries. Companies with nothing slabbed in stock are simply
     * absent, same rule as the eras.
     *
     * @param array<int, array> $nodes
     * @return array<int, array>
     */
    private function rewriteGradedEntry(array $nodes): array
    {
        $idLang = (int) $this->context->language->id;

        $gradedId = (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
               JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cl.id_category
              WHERE cl.id_lang = 1 AND cl.name = "Graded"'
        );
        $singlesId = (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
              WHERE cl.id_lang = 1 AND cl.name = "Singles"'
        );
        if (!$gradedId || !$singlesId) {
            return $nodes;
        }

        // The facet URL speaks the storefront's language: the group's public name
        // is localised, so the French filter is Gradation-PSA, not Grading-PSA.
        $groupName = (string) Db::getInstance()->getValue(
            'SELECT public_name FROM ' . _DB_PREFIX_ . 'attribute_group_lang
              WHERE id_lang = ' . $idLang . ' AND name IN ("Grading", "Gradation")'
        );

        $stockedGraders = Db::getInstance()->executeS(
            'SELECT DISTINCT al.name
               FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                    ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = 1 AND agl.name = "Grading"
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product_attribute = pac.id_product_attribute AND sa.quantity > 0
              WHERE al.name != "Ungraded"'
        ) ?: [];

        $singlesUrl = $this->context->link->getCategoryLink($singlesId);
        $glue = str_contains($singlesUrl, '?') ? '&' : '?';

        foreach ($nodes as $index => $node) {
            if (!is_array($node) || ($node['page_identifier'] ?? '') !== 'category-' . $this->pokemonCategoryId()) {
                continue;
            }
            foreach ($node['children'] ?? [] as $childIndex => $child) {
                if (($child['page_identifier'] ?? '') !== 'category-' . $gradedId) {
                    continue;
                }
                // Multi-value facet syntax is DASH-separated (Grading-PSA-BGS);
                // a pipe reads as one unknown value and silently unfilters.
                $all = implode('-', array_column($stockedGraders, 'name'));
                $nodes[$index]['children'][$childIndex]['url'] = $all === ''
                    ? $singlesUrl
                    : $singlesUrl . $glue . 'q=' . rawurlencode($groupName . '-' . $all);
                $nodes[$index]['children'][$childIndex]['children'] = array_map(
                    fn (array $grader) => [
                        'type' => 'link',
                        'page_identifier' => 'grader-' . $grader['name'],
                        'label' => (string) $grader['name'],
                        'url' => $singlesUrl . $glue . 'q=' . rawurlencode($groupName . '-' . $grader['name']),
                        'children' => [],
                        'open_in_new_window' => false,
                        'image_urls' => [],
                    ],
                    $stockedGraders
                );
                break 2;
            }
        }

        return $nodes;
    }

    /**
     * Adds "Sets" to the game's dropdown, pointing at the set directory.
     *
     * The dropdown lists what you can shop: Singles, Sealed, Graded. Set browsing is
     * the fourth way in and it lived only in the top bar, so the one menu a shopper
     * opens did not mention it. It replaces Accessories, which was a whole branch
     * for a product line this shop does not sell.
     *
     * A link rather than a category: the set tree is 217 entries deep and belongs on
     * a page, not in a hover panel.
     *
     * @param array<int, array> $nodes
     * @return array<int, array>
     */
    private function addSetsLink(array $nodes): array
    {
        $page = Db::getInstance()->getRow(
            'SELECT c.id_cms, cl.meta_title
               FROM ' . _DB_PREFIX_ . 'cms c
               JOIN ' . _DB_PREFIX_ . 'cms_lang cl
                    ON cl.id_cms = c.id_cms AND cl.id_lang = ' . (int) $this->context->language->id . '
              WHERE cl.link_rewrite = "pokemon-sets"'
        );
        if (!$page) {
            return $nodes;
        }

        foreach ($nodes as $index => $node) {
            if (!is_array($node) || ($node['page_identifier'] ?? '') !== 'category-' . $this->pokemonCategoryId()) {
                continue;
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            foreach ($children as $child) {
                if (($child['page_identifier'] ?? '') === 'cms-page-' . (int) $page['id_cms']) {
                    return $nodes;   // already there
                }
            }

            $children[] = [
                'type' => 'cms-page',
                'page_identifier' => 'cms-page-' . (int) $page['id_cms'],
                'label' => (string) $page['meta_title'],
                'url' => $this->context->link->getCMSLink(
                    new CMS((int) $page['id_cms'], (int) $this->context->language->id)
                ),
                'children' => [],
                'open_in_new_window' => false,
                'image_urls' => [],
            ];
            $nodes[$index]['children'] = $children;
            break;
        }

        return $nodes;
    }

    private function pokemonCategoryId(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
              WHERE cl.id_lang = 1 AND cl.name = "Pokémon"'
        );
    }

    /** @return array<int, array> */
    private function pruneMenu(array $nodes, int $depth): array
    {
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $nodes[$index]['children'] = $depth >= self::MENU_MAX_DEPTH
                ? []
                : $this->pruneMenu($children, $depth + 1);
        }

        return $nodes;
    }

    public function hookDisplayHeader(): void
    {
        $this->exposeStockLevels();

        // Listing pages expand one tile per printing; the endpoint tells them how.
        Media::addJsDef([
            'cryptocardsPrintingsUrl' => $this->context->link->getModuleLink(
                $this->name, 'printings', [], true
            ),
            // Listing tiles need this too, so it sits outside exposeStockLevels()
            // - that method returns early on anything but a product page.
            'cryptocardsPrintRuns' => $this->printRunSets(),
            'cryptocardsEditionSets' => $this->editionSplitSets(),
            'cryptocardsI18n' => $this->translations(),
            'cryptocardsAttrSlugs' => $this->attributeSlugs(),
            'cryptocardsConditions' => $this->conditionMap(),
            'cryptocardsPrintings' => $this->printingMap(),
            'cryptocardsLanguageFacet' => $this->languageFacet(),
            'cryptocardsCartChips' => $this->cartChips(),
            'cryptocardsPrintingsById' => $this->printingsById(),
            'cryptocardsConditionsById' => $this->conditionsById(),
            'cryptocardsLanguages' => $this->languageMap(),
            'cryptocardsLanguagesById' => $this->languagesById(),
            'cryptocardsStats' => $this->homepageStats(),
            'cryptocardsNavImages' => $this->navImages(),
            // Quick view happens on LISTING pages, so the endpoint that feeds it
            // cannot live inside the product-page-only block below.
            'cryptocardsContextUrl' => $this->context->link->getModuleLink(
                $this->name, 'context', [], true
            ),
        ]);

        // Priority is deliberately very high so this lands after the theme's own
        // stylesheet in the compiled bundle and actually wins the cascade.
        $this->context->controller->registerStylesheet(
            'cryptocards-theme',
            'modules/' . $this->name . '/views/css/theme.css',
            ['media' => 'all', 'priority' => 1000]
        );
        $this->context->controller->registerJavascript(
            'cryptocards-theme',
            'modules/' . $this->name . '/views/js/theme.js',
            ['position' => 'bottom', 'priority' => 1000]
        );
    }

    /**
     * Publishes per-SKU and total stock for the product being viewed.
     *
     * The theme shows only a vague "Last items in stock" banner, and nothing at all
     * about the other variants. A card buyer wants two numbers: how many of the
     * exact printing/condition they picked, and how deep the stock is overall.
     * Neither is in the page, so hand them to the front end here.
     */
    private function exposeStockLevels(): void
    {
        if ($this->context->controller->php_self !== 'product') {
            return;
        }

        $productId = (int) Tools::getValue('id_product');
        if ($productId <= 0) {
            return;
        }

        Media::addJsDef($this->productContext($productId));
    }

    /**
     * Everything the product page needs about one product, in one payload.
     *
     * Public and product-id-driven so the quick-view modal can ask for it: the
     * modal renders the same product markup from a LISTING page, where none of
     * these globals exist, and it was the only surface still showing a bare
     * Hummingbird product with no stock box, no badge line and no copy picker.
     *
     * @return array{cryptocardsStock: array, cryptocardsSet: array, cryptocardsCopies: array}
     */
    public function productContext(int $productId): array
    {
        return [
            'cryptocardsStock' => $this->stockContext($productId),
            'cryptocardsSet' => $this->setContext($productId),
            'cryptocardsCopies' => $this->copyContext($productId),
        ];
    }

    private function stockContext(int $productId): array
    {
        $total = (int) Db::getInstance()->getValue(
            'SELECT quantity FROM ' . _DB_PREFIX_ . 'stock_available
              WHERE id_product = ' . $productId . ' AND id_product_attribute = 0'
        );

        /**
         * Keyed by a sorted attribute-id signature ("26-31-41") rather than by
         * id_product_attribute: Hummingbird renders no id_product_attribute input
         * and exposes no prestashop.product object, but its variant <select>s carry
         * attribute ids as option values - so the signature is derivable client-side.
         */
        $rows = Db::getInstance()->executeS(
            'SELECT pac.id_product_attribute,
                    GROUP_CONCAT(pac.id_attribute ORDER BY pac.id_attribute) AS signature,
                    sa.quantity
               FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
               JOIN ' . _DB_PREFIX_ . 'product_attribute pa
                    ON pa.id_product_attribute = pac.id_product_attribute
               LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product_attribute = pac.id_product_attribute
              WHERE pa.id_product = ' . $productId . '
              GROUP BY pac.id_product_attribute'
        ) ?: [];

        $variants = [];
        $signatureToSku = [];
        foreach ($rows as $row) {
            $variants[(string) $row['signature']] = (int) $row['quantity'];
            // Copy data is keyed by id_product_attribute, the selectors give a
            // signature - this is the bridge between the two.
            $signatureToSku[(string) $row['signature']] = (string) (int) $row['id_product_attribute'];
        }

        return [
            'total' => $total,
            'variants' => $variants,
            'signatureToSku' => $signatureToSku,
            'skuCount' => count($variants),
        ];
    }

    /**
     * Serialised copies for this product, grouped by SKU.
     *
     * Drives the photography rule in docs/operations-pipeline.md §2.4:
     *
     *   sealed                  -> never serialised, always the stock photo
     *   1 available + photo     -> that copy's photo IS the product image
     *   2+ available + photos   -> stock photo, plus "choose your exact card"
     *   no photo captured yet   -> stock photo, and say photography is pending
     *   policy = stock_only     -> stock photo, and say so plainly (bulk)
     *
     * The last two rules are separate on purpose. "Not shot yet" and "never going
     * to be shot" look identical in the data but mean opposite things to a buyer,
     * so the panel always renders and always states which one applies - silence
     * reads as an omission.
     */
    public function copyContext(int $productId): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_copy, c.copy_uid, c.id_product_attribute, c.photo_state, c.photo_policy
               FROM ' . _DB_PREFIX_ . 'card_copy c
              WHERE c.id_product = ' . $productId . ' AND c.status = "available"
              ORDER BY c.id_copy'
        ) ?: [];

        if (!$rows) {
            return ['serialised' => false, 'skus' => []];
        }

        // Copy photos live outside PrestaShop's image system on purpose: they belong
        // to one serial, not to the printing, and must never leak into the product
        // gallery. Several per copy - front, back, and any detail shots.
        $photos = [];
        foreach (Db::getInstance()->executeS(
            'SELECT ci.id_copy, ci.filename, ci.side, ci.is_placeholder
               FROM ' . _DB_PREFIX_ . 'card_copy_image ci
               JOIN ' . _DB_PREFIX_ . 'card_copy c ON c.id_copy = ci.id_copy
              WHERE c.id_product = ' . $productId . '
              ORDER BY ci.id_copy, ci.position'
        ) ?: [] as $row) {
            $photos[(int) $row['id_copy']][] = [
                'url' => __PS_BASE_URI__ . 'img/cc-copies/' . rawurlencode((string) $row['filename']),
                'side' => (string) $row['side'],
                'placeholder' => (bool) $row['is_placeholder'],
            ];
        }

        $skus = [];
        foreach ($rows as $row) {
            $skuId = (string) (int) $row['id_product_attribute'];
            $copyId = (int) $row['id_copy'];
            $shots = $photos[$copyId] ?? [];

            $skus[$skuId]['copies'][] = [
                'uid' => (string) $row['copy_uid'],
                'captured' => $shots !== [],
                'image' => $shots[0]['url'] ?? null,
                'photos' => $shots,
                'policy' => (string) $row['photo_policy'],
            ];
        }

        foreach ($skus as $skuId => $data) {
            $withPhotos = array_filter($data['copies'], static fn ($copy) => $copy['captured']);
            $skus[$skuId]['count'] = count($data['copies']);
            $skus[$skuId]['photographed'] = count($withPhotos);
            // A SKU counts as stock-only when every copy in it is flagged that way.
            // One copy still queued for the camera means the SKU is pending, not
            // abandoned - so the buyer is never told "no photos" while one is coming.
            $stockOnly = array_filter($data['copies'], static fn ($copy) => $copy['policy'] === 'stock_only');
            $skus[$skuId]['policy'] = count($stockOnly) === count($data['copies']) ? 'stock_only' : 'per_copy';
        }

        return ['serialised' => true, 'skus' => $skus];
    }

    /**
     * The set, and whether this card's print run is shadowed or shadowless.
     *
     * TCGplayer runs `Base Set` and `Base Set (Shadowless)` as parallel groups
     * containing the same cards at very different values. Leaving that to the
     * breadcrumb, or to a row inside a collapsed data sheet, is not good enough on
     * a four-figure vintage card - the buyer has to see it next to the title.
     *
     * Derived rather than hardcoded: a set is "shadowless" if its name says so, and
     * "shadowed" if a sibling category exists with the same name plus that suffix.
     * If Shadowless groups ever appear for other sets, this picks them up for free.
     */
    public function setContext(int $productId): array
    {
        $idLang = (int) $this->context->language->id;

        /**
         * Display name in the current language, print run decided on the ENGLISH
         * one.
         *
         * Testing the localised name for "(Shadowless)" meant the badge simply
         * never rendered in French, where the category is "Set de Base (Sans
         * ombre)" - so the single most valuable distinction on this site was
         * silently absent on one of its two storefronts.
         */
        $row = Db::getInstance()->getRow(
            'SELECT cl.name, en.name AS name_en, p.id_category_default
               FROM ' . _DB_PREFIX_ . 'product p
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = p.id_category_default AND cl.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'category_lang en
                    ON en.id_category = p.id_category_default AND en.id_lang = 1
              WHERE p.id_product = ' . $productId
        );

        $setName = (string) ($row['name'] ?? '');
        $setNameEn = (string) ($row['name_en'] ?? '');
        // Language is a variant now, so the badge line reads it from the selected
        // SKU client-side; nothing product-level to publish.
        $language = '';
        /**
         * Rarity was stated only as body text and in the data sheet, while the CART
         * showed it as a chip - so the two pages disagreed about how important it
         * is. It is part of the card's identity; it belongs on the badge line.
         */
        $rarity = $this->productFeature($productId, 'Rarity', $idLang);

        if ($setName === '') {
            return ['name' => '', 'printRun' => null, 'language' => $language, 'rarity' => $rarity];
        }

        if (preg_match('/\(Shadowless\)$/i', $setNameEn)) {
            return ['name' => $setName, 'printRun' => 'shadowless',
                    'language' => $language, 'rarity' => $rarity];
        }

        $hasShadowlessSibling = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'category_lang
              WHERE id_lang = 1 AND name = "' . pSQL($setNameEn . ' (Shadowless)') . '"'
        );

        return [
            'name' => $setName,
            'printRun' => $hasShadowlessSibling > 0 ? 'shadowed' : null,
            'language' => $language,
            'rarity' => $rarity,
        ];
    }

    /**
     * Printing attribute id => label and chip class, for the badge line.
     *
     * Keyed by ID, not by slug or label: on a product page the selected printing is
     * read from the variant <select>, whose option VALUES are attribute ids. Every
     * other handle there is translated, so matching on one would work in English
     * and silently stop working in French - which is how three earlier bugs
     * happened.
     *
     * @return array<string, array{label: string, cls: string, skip: bool}>
     */
    private function printingsById(): array
    {
        $idLang = (int) $this->context->language->id;

        $out = [];
        foreach (Db::getInstance()->executeS(
            'SELECT a.id_attribute, al.name, en.name AS name_en
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_lang en
                    ON en.id_attribute = a.id_attribute AND en.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang g
                    ON g.id_attribute_group = a.id_attribute_group AND g.id_lang = 1
              WHERE g.name = "Printing"'
        ) ?: [] as $row) {
            $english = (string) $row['name_en'];

            $out[(string) (int) $row['id_attribute']] = [
                'label' => (string) $row['name'],
                // One printing colour, everywhere. See editionOf().
                'cls' => 'cc-chip--printing',
                'edition' => $this->editionOf($english),
                // "Normal" is the absence of a special printing - decided on the
                // ENGLISH name, as everywhere else.
                'skip' => strcasecmp($english, 'Normal') === 0,
            ];
        }

        return $out;
    }

    /**
     * Every cart line's facets, as chips, keyed by combination id.
     *
     * The cart printed "Impression: Holo" and "État: Quasi neuf (NM)" as label/value
     * text and nothing else - so the page where a buyer confirms a four-figure
     * purchase said less about the card than the tile they clicked to get there.
     * Rarity, print run and card language were all absent.
     *
     * Composed server-side rather than reverse-engineered from the rendered labels:
     * the class that colours a condition chip is decided by attribute POSITION, and
     * that is not recoverable from a translated string.
     *
     * @return array<string, array<int, array{label: string, cls: string}>>
     */
    private function cartChips(): array
    {
        if (!in_array($this->context->controller->php_self, ['cart', 'order'], true)) {
            return [];
        }
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return [];
        }

        $skuIds = [];
        $productIds = [];
        foreach ($cart->getProducts() as $product) {
            $skuIds[] = (int) $product['id_product_attribute'];
            $productIds[(int) $product['id_product']] = true;
        }
        $skuIds = array_values(array_filter(array_unique($skuIds)));
        if ($skuIds === [] || $productIds === []) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $conditions = $this->conditionMap();
        $printings = $this->printingMap();
        $printRuns = $this->printRunSets();

        // Condition and Printing come off the combination; groups are matched on
        // their ENGLISH name because the labels are translated.
        $bySku = [];
        foreach (Db::getInstance()->executeS(
            'SELECT pac.id_product_attribute, en.name AS grp, al.name AS value
               FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                    ON en.id_attribute_group = a.id_attribute_group AND en.id_lang = 1
              WHERE pac.id_product_attribute IN (' . implode(',', $skuIds) . ')'
        ) ?: [] as $row) {
            $bySku[(int) $row['id_product_attribute']][(string) $row['grp']] = (string) $row['value'];
        }

        // Rarity, Card Language and the set, per product.
        $byProduct = [];
        foreach (Db::getInstance()->executeS(
            'SELECT fp.id_product, en.name AS feature, fvl.value
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang en
                    ON en.id_feature = fp.id_feature AND en.id_lang = 1
                   AND en.name IN ("Rarity")
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
              WHERE fp.id_product IN (' . implode(',', array_map('intval', array_keys($productIds))) . ')'
        ) ?: [] as $row) {
            $byProduct[(int) $row['id_product']][(string) $row['feature']] = (string) $row['value'];
        }

        $setByProduct = [];
        foreach (Db::getInstance()->executeS(
            'SELECT p.id_product, cl.name
               FROM ' . _DB_PREFIX_ . 'product p
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = p.id_category_default AND cl.id_lang = ' . $idLang . '
              WHERE p.id_product IN (' . implode(',', array_map('intval', array_keys($productIds))) . ')'
        ) ?: [] as $row) {
            $setByProduct[(int) $row['id_product']] = (string) $row['name'];
        }

        $out = [];
        foreach ($cart->getProducts() as $product) {
            $skuId = (int) $product['id_product_attribute'];
            $productId = (int) $product['id_product'];
            $chips = [];

            $condition = $bySku[$skuId]['Condition'] ?? '';
            if ($condition !== '') {
                $slug = $this->anchorSlug($condition);
                $chips[] = [
                    'label' => $condition,
                    'cls' => 'cc-chip--cond ' . ($conditions[$slug]['cls'] ?? ''),
                ];
            }

            $printing = $bySku[$skuId]['Printing'] ?? '';
            if ($printing !== '') {
                $slug = $this->anchorSlug($printing);
                // "Normal" is the absence of a special printing, so it earns no chip
                // here for the same reason it earns none on a tile.
                if (!($printings[$slug]['skip'] ?? false)) {
                    $chips[] = ['label' => $printing, 'cls' => 'cc-chip--printing'];
                }
            }

            // Shadowed vs shadowless is the largest price difference in the shop and
            // is invisible in the title, so it is stated on the line being bought.
            $run = $printRuns[$setByProduct[$productId] ?? ''] ?? null;
            if ($run !== null) {
                $chips[] = [
                    'label' => $this->translations()[$run === 'shadowless' ? 'shadowless' : 'shadowedBadge'] ?? $run,
                    'cls' => 'cc-chip--run cc-chip--' . $run,
                ];
            }

            $rarity = $byProduct[$productId]['Rarity'] ?? '';
            if ($rarity !== '') {
                $chips[] = ['label' => $rarity, 'cls' => 'cc-chip--rarity'];
            }

            // From the COMBINATION - a product can hold several languages now.
            $language = $bySku[$skuId]['Card Language'] ?? '';
            if ($language !== '') {
                $chips[] = ['label' => $language, 'cls' => 'cc-chip--language'];
            }

            if ($chips !== []) {
                $out[(string) $skuId] = $chips;
            }
        }

        return $out;
    }

    /**
     * The Card Language facet, so the rail can show it even when PrestaShop won't.
     *
     * ps_facetedsearch hides any facet whose single value covers the entire result
     * set - reasonable in general, and wrong here. Card language is the axis this
     * shop is built to sell across, and a rail that shows it only once a second
     * language is in stock tells a shopper the shop deals in one language. It also
     * offers no hook to override the decision, so the block is emitted here and the
     * front end inserts it when the native one is absent.
     *
     * The counts and links are real: filtering by the single value is a valid query
     * that returns exactly what it says.
     *
     * @return array{label: string, values: array<int, array{name: string, count: int}>}
     */
    private function languageFacet(): array
    {
        if (!in_array($this->context->controller->php_self, ['category', 'search', 'best-sales', 'new-products'], true)) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $categoryId = (int) Tools::getValue('id_category');
        if ($categoryId <= 0) {
            return [];
        }

        $label = (string) Db::getInstance()->getValue(
            'SELECT fl.name FROM ' . _DB_PREFIX_ . 'feature_lang fl
               JOIN ' . _DB_PREFIX_ . 'feature_lang en
                    ON en.id_feature = fl.id_feature AND en.id_lang = 1 AND en.name = "Card Language"
              WHERE fl.id_lang = ' . $idLang
        );
        if ($label === '') {
            return [];
        }

        // Counts over the category's own products, which is the set the rail is
        // describing. Nested categories are included, same as the facet itself.
        $rows = Db::getInstance()->executeS(
            'SELECT fvl.value, COUNT(DISTINCT cp.id_product) AS magnitude
               FROM ' . _DB_PREFIX_ . 'category_product cp
               JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cp.id_category
               JOIN ' . _DB_PREFIX_ . 'category parent
                    ON parent.id_category = ' . $categoryId . '
                   AND c.nleft >= parent.nleft AND c.nright <= parent.nright
               JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = cp.id_product AND p.active = 1
               JOIN ' . _DB_PREFIX_ . 'feature_product fp ON fp.id_product = cp.id_product
               JOIN ' . _DB_PREFIX_ . 'feature_lang en
                    ON en.id_feature = fp.id_feature AND en.id_lang = 1 AND en.name = "Card Language"
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
              GROUP BY fvl.value
              ORDER BY magnitude DESC, fvl.value'
        ) ?: [];

        if ($rows === []) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $values[] = ['name' => (string) $row['value'], 'count' => (int) $row['magnitude']];
        }

        return ['label' => $label, 'values' => $values];
    }

    /**
     * One feature value for a product, in the given language.
     *
     * The feature is found by its ENGLISH name and read in the target language -
     * matching the localised name finds nothing the moment a label is translated.
     */
    private function productFeature(int $productId, string $englishFeature, int $idLang): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT fvl.value
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                    ON fl.id_feature = fp.id_feature AND fl.id_lang = 1
                   AND fl.name = "' . pSQL($englishFeature) . '"
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
              WHERE fp.id_product = ' . $productId
        );
    }

    /**
     * Every set that participates in a parallel print run, as name => run.
     *
     * The product page shows this next to the title, but a shopper comparing
     * tiles in a listing needs it too - "Base Set" and "Base Set (Shadowless)"
     * hold the SAME cards at the same collector numbers, and a Charizard from one
     * is worth several times the other. Audited across all 217 TCGplayer groups
     * (provisioning/audit-parallel-sets.php): Base Set is the only such pair, but
     * this is derived, so a future one is picked up without a code change.
     *
     * @return array<string,string> set name => 'shadowed'|'shadowless'
     */
    private function printRunSets(): array
    {
        $idLang = (int) $this->context->language->id;

        /**
         * Found by the ENGLISH name, returned under the CURRENT one.
         *
         * Matching `LIKE "%(Shadowless)"` against the localised name found nothing
         * on the French storefront - the category is "Set de Base (Sans ombre)" -
         * so the map came back empty and NEITHER print-run chip rendered in French.
         * The single most valuable distinction in the catalogue was missing from
         * every French tile and every French cart line.
         */
        $names = [];
        foreach (Db::getInstance()->executeS(
            'SELECT en.name AS name_en, cl.name AS name_cur
               FROM ' . _DB_PREFIX_ . 'category_lang en
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = en.id_category AND cl.id_lang = ' . $idLang . '
              WHERE en.id_lang = 1'
        ) ?: [] as $row) {
            $names[(string) $row['name_en']] = (string) $row['name_cur'];
        }

        $map = [];
        foreach ($names as $englishName => $localisedName) {
            if (!preg_match('/\s*\(Shadowless\)$/i', $englishName)) {
                continue;
            }
            $map[$localisedName] = 'shadowless';

            // Its shadowed counterpart is the same English name without the suffix.
            $baseEnglish = trim((string) preg_replace('/\s*\(Shadowless\)$/i', '', $englishName));
            if ($baseEnglish !== '' && isset($names[$baseEnglish])) {
                $map[$names[$baseEnglish]] = 'shadowed';
            }
        }

        return $map;
    }

    /**
     * URL-anchor slug => the real label and chip class, for the current language.
     *
     * Tile chips are derived from the combination anchor
     * ("#/26-etat-quasi_neuf_nm/..."), and that slug is LOSSY: title-casing it
     * produced "Quasi Neuf Nm" instead of "Quasi neuf (NM)", and it could not be
     * matched back to a colour class because the brackets are gone.
     *
     * Emitting slug => {label, cls} fixes both at once and stays correct in any
     * language, because the slug is computed here the same way PrestaShop builds
     * it. Class is assigned by attribute POSITION, so the best grade is always
     * green regardless of what it is called.
     *
     * @return array<string, array{label:string, cls:string}>
     */
    private function conditionMap(): array
    {
        $idLang = (int) $this->context->language->id;
        $classes = ['cc-chip--nm', 'cc-chip--lp', 'cc-chip--mp', 'cc-chip--hp', 'cc-chip--dmg'];

        $rows = Db::getInstance()->executeS(
            'SELECT al.name, a.position
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                    ON en.id_attribute_group = a.id_attribute_group AND en.id_lang = 1
              WHERE en.name = "Condition"
              ORDER BY a.position'
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $position = (int) $row['position'];
            $map[$this->anchorSlug($name)] = [
                'label' => $name,
                'cls' => $classes[$position] ?? '',
            ];
        }

        return $map;
    }

    /**
     * Printing slug => label, plus whether the chip should be suppressed.
     *
     * "Normal" carries no information on a tile - it is the absence of a special
     * printing - so it renders no chip. That was decided by comparing the slug to
     * the literal "normal", which broke the moment printings were translated: the
     * French slug is "normale" and a pointless "Normale" chip appeared on every
     * plain card. The decision is made here against the ENGLISH name instead.
     */
    private function printingMap(): array
    {
        $idLang = (int) $this->context->language->id;
        $map = [];

        foreach (Db::getInstance()->executeS(
            'SELECT al.name, en_val.name AS english
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_lang en_val
                    ON en_val.id_attribute = a.id_attribute AND en_val.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                    ON en.id_attribute_group = a.id_attribute_group AND en.id_lang = 1
              WHERE en.name = "Printing"
              ORDER BY a.position'
        ) ?: [] as $row) {
            $name = (string) $row['name'];
            $map[$this->anchorSlug($name)] = [
                'label' => $name,
                'skip' => strcasecmp((string) $row['english'], 'Normal') === 0,
                'edition' => $this->editionOf((string) $row['english']),
            ];
        }

        return $map;
    }

    /**
     * Category id => background artwork for its menu entry.
     *
     * Generated by seed-nav-images.php from artwork already in the catalogue, so a
     * dropdown of era names becomes a wall of era logos and "Sealed" shows a booster
     * wrapper. Only what exists on disk is published, so a missing file degrades to
     * the plain tile it is today rather than a broken image.
     *
     * @return array<string, string>
     */
    private function navImages(): array
    {
        $directory = _PS_IMG_DIR_ . 'nav/';
        if (!is_dir($directory)) {
            return [];
        }

        /**
         * ERAS only.
         *
         * Sealed's product types and Graded's five graders own no artwork of their
         * own, so they were all inheriting the same borrowed photo - the same card
         * repeated down a column, which reads as a rendering bug rather than as
         * decoration. Only the era logos say something the label does not.
         */
        /**
         * Matched through the region node: eras are grandchildren of Singles now,
         * not children. Pinned to id_lang 1 on BOTH levels - resolving catalogue
         * identity by a localised name returns zero rows in French, which is how
         * this silently emptied the artwork map for the whole French storefront
         * before, and how it emptied it for both when region moved in between.
         */
        $eras = [];
        foreach (Db::getInstance()->executeS(
            'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category region
                    ON region.id_category = c.id_parent
               JOIN ' . _DB_PREFIX_ . 'category_lang singles
                    ON singles.id_category = region.id_parent AND singles.id_lang = 1
              WHERE singles.name = "Singles" AND c.active = 1'
        ) ?: [] as $row) {
            $eras[(string) (int) $row['id_category']] = true;
        }

        $out = [];
        foreach (glob($directory . '*.png') ?: [] as $file) {
            $key = basename($file, '.png');
            if (isset($eras[$key])) {
                $out[$key] = _PS_IMG_ . 'nav/' . basename($file);
            }
        }

        return $out;
    }

    /**
     * Live homepage figures.
     *
     * The hero is a CMS block, so anything written into it is frozen at
     * provisioning time - the page was advertising 391 sets catalogued against a
     * 217-set catalogue, and a stock count that had not moved since the last
     * seeding run. Recomputed per request and written into the [data-cc-stat]
     * slots, so a number on the front page is never older than the page itself.
     *
     * Only on the home page: four aggregate queries on every product view would be
     * four queries wasted.
     *
     * @return array<string, string>
     */
    private function homepageStats(): array
    {
        if ($this->context->controller->php_self !== 'index') {
            return [];
        }

        $db = Db::getInstance();
        $cards = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy WHERE status = "available"'
        );
        $sets = (int) $db->getValue(
            'SELECT COUNT(DISTINCT c.id_category)
               FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_product cp ON cp.id_category = c.id_category
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0
              WHERE c.level_depth = 5 AND c.active = 1'
        );
        $sealed = (int) $db->getValue(
            'SELECT IFNULL(SUM(sa.quantity), 0)
               FROM ' . _DB_PREFIX_ . 'category_product cp
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = cp.id_category AND cl.id_lang = 1 AND cl.name = "Sealed"
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0'
        );
        $oldest = (int) $db->getValue(
            'SELECT MIN(YEAR(g.published_on))
               FROM ' . _DB_PREFIX_ . 'tcg_group_category g
               JOIN ' . _DB_PREFIX_ . 'category_product cp ON cp.id_category = g.id_category
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0'
        );

        /**
         * Grouped through the storefront's own locale, so 1234 reads "1,234" in
         * English and "1 234" in French rather than however the CMS block happened
         * to be generated. Not Tools::displayNumber() - that does not exist in
         * PrestaShop 9 and took the whole home page down with a fatal.
         */
        $locale = Context::getContext()->getCurrentLocale();
        $group = static fn (int $value): string => $locale
            ? $locale->formatNumber($value)
            : number_format($value);

        return [
            'cards' => $group($cards),
            'sets' => $group($sets),
            'sealed' => $group($sealed),
            // A year is never grouped: "1,999" is not a year.
            'oldest' => $oldest > 0 ? (string) $oldest : '',
        ];
    }

    /**
     * Card-language slug => label, for tile chips read off the combination anchor.
     *
     * Same shape as conditionMap()/printingMap(), because a tile learns its SKU from
     * the URL fragment and every slug in it is localised.
     *
     * @return array<string, array{label: string}>
     */
    private function languageMap(): array
    {
        $idLang = (int) $this->context->language->id;

        $map = [];
        foreach (Db::getInstance()->executeS(
            'SELECT al.name
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang g
                    ON g.id_attribute_group = a.id_attribute_group AND g.id_lang = 1
              WHERE g.name = "Card Language"
              ORDER BY a.position'
        ) ?: [] as $row) {
            $map[$this->anchorSlug((string) $row['name'])] = ['label' => (string) $row['name']];
        }

        return $map;
    }

    /** Card-language attribute id => label, for the product page badge line. */
    private function languagesById(): array
    {
        $idLang = (int) $this->context->language->id;

        $out = [];
        foreach (Db::getInstance()->executeS(
            'SELECT a.id_attribute, al.name
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang g
                    ON g.id_attribute_group = a.id_attribute_group AND g.id_lang = 1
              WHERE g.name = "Card Language"'
        ) ?: [] as $row) {
            $out[(string) (int) $row['id_attribute']] = [
                'label' => (string) $row['name'],
                'cls' => 'cc-chip--language',
            ];
        }

        return $out;
    }

    /**
     * Condition attribute id => label and chip class.
     *
     * Keyed by ID for the same reason printings are: on a product page the chosen
     * condition is read from the variant <select>, whose option values are attribute
     * ids, and every label there is translated.
     *
     * @return array<string, array{label: string, cls: string}>
     */
    private function conditionsById(): array
    {
        $idLang = (int) $this->context->language->id;
        $classes = ['cc-chip--nm', 'cc-chip--lp', 'cc-chip--mp', 'cc-chip--hp', 'cc-chip--dmg'];

        $out = [];
        foreach (Db::getInstance()->executeS(
            'SELECT a.id_attribute, a.position, al.name
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang g
                    ON g.id_attribute_group = a.id_attribute_group AND g.id_lang = 1
              WHERE g.name = "Condition"
              ORDER BY a.position'
        ) ?: [] as $row) {
            // Colour by POSITION - conditions are created best-to-worst, so the
            // ramp is correct in any language and survives renaming a grade.
            $out[(string) (int) $row['id_attribute']] = [
                'label' => (string) $row['name'],
                'cls' => 'cc-chip--cond ' . ($classes[(int) $row['position']] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Which print edition a printing states, or null.
     *
     * A MARKER, not a colour. 1st Edition and Unlimited used to get their own chip
     * colours, which made 1st Edition look identical to Shadowless - two unrelated
     * facts wearing the same amber - and the colours were assigned by matching a
     * TRANSLATED label, so they never applied on the French storefront at all.
     * Printing now has one colour everywhere and this marker carries the fact, so
     * the "edition not set" check still works without being a visual decision.
     */
    private function editionOf(string $englishPrinting): ?string
    {
        $lower = strtolower($englishPrinting);
        if (str_starts_with($lower, '1st edition')) {
            return '1st';
        }
        if (str_starts_with($lower, 'unlimited')) {
            return 'unlimited';
        }

        return null;
    }

    /** The slug PrestaShop puts in a combination anchor for an attribute value. */
    private function anchorSlug(string $name): string
    {
        $slug = Tools::str2url($name) ?: strtolower($name);

        return str_replace('-', '_', strtolower((string) $slug));
    }

    /**
     * Canonical attribute-group key => the slug used in product URL anchors.
     *
     * The listing reads a tile's printing and condition out of the combination
     * anchor, e.g. "#/26-condition-near_mint/42-printing-holofoil". Those segments
     * are built from the attribute group's name IN THE CURRENT LANGUAGE, so on the
     * French storefront the same card reads "#/26-etat-near_mint/50-impression-...".
     *
     * Matching the literal "condition" therefore found nothing in French and every
     * tile rendered without chips. Emitting the real slugs keeps the parser working
     * in any language without hardcoding translations in JS.
     *
     * @return array<string,string>
     */
    private function attributeSlugs(): array
    {
        $idLang = (int) $this->context->language->id;
        $canonical = [
            'condition' => 'Condition',
            'printing' => 'Printing',
            'language' => 'Card Language',
        ];
        $slugs = [];

        foreach ($canonical as $key => $englishName) {
            $name = (string) Db::getInstance()->getValue(
                'SELECT agl.name
                   FROM ' . _DB_PREFIX_ . 'attribute_group_lang agl
                   JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                        ON en.id_attribute_group = agl.id_attribute_group AND en.id_lang = 1
                  WHERE agl.id_lang = ' . $idLang . ' AND en.name = "' . pSQL($englishName) . '"'
            );
            if ($name === '') {
                $name = $englishName;
            }
            // PrestaShop slugifies with underscores: "État" -> "etat",
            // "Impression" -> "impression".
            $slug = Tools::str2url($name) ?: strtolower($name);
            $slugs[$key] = str_replace('-', '_', strtolower((string) $slug));
        }

        return $slugs;
    }

    /**
     * Every string the front-end script renders, in the active language.
     *
     * These strings are injected by JS and therefore bypass PrestaShop's
     * translation system entirely - a literal in theme.js renders identically in
     * both locales. Left alone, the French storefront showed French breadcrumbs
     * and chrome wrapped around English stock boxes, photo panels and chips.
     *
     * Keys are stable; the JS falls back to the English value if a key is ever
     * missing, so a new string is never a blank label.
     *
     * @return array<string,string>
     */
    private function translations(): array
    {
        $en = [
            'inStock' => 'in stock',
            'outOfStock' => 'Out of stock',
            'selectVariant' => 'Select a variant',
            'thisPrintingCondition' => 'this printing &amp; condition',
            'total' => 'total',
            'acrossVariant' => 'across %d variant',
            'acrossVariants' => 'across %d variants',

            'exactCardAbove' => 'Photographed above is <strong>the exact card you will receive</strong>',
            'serial' => 'Serial',
            'frontAndBack' => 'front and back shown',
            'photoPending' => 'Photo of this exact card pending — the image above is a reference scan.',
            'soldByCondition' => 'Sold by condition — not individually photographed',
            'soldByConditionBody' => 'We do not photograph single copies of this card. The image above is '
                . 'a reference scan; yours is hand-checked to the stated condition.',
            'notPhotographedYet' => 'Individual copies not photographed yet',
            'notPhotographedYetBody' => 'The image above is a reference scan. We ship the oldest copy in '
                . 'stock, hand-checked to the stated condition.',
            'chooseExactCard' => 'Choose your exact card',
            'photographed' => 'photographed',
            'copyPickerHint' => 'Optional — leave this alone and we ship the oldest copy in stock.',
            'reservedForYou' => 'Reserved for you at checkout:',
            'noCopyChosen' => 'No specific card chosen — we ship the oldest in stock.',

            'shadowless' => 'Shadowless',
            'shadowed' => 'Shadowed',
            'shadowlessTitle' => 'Shadowless print run — no drop shadow on the art box',
            'shadowedTitle' => 'Shadowed print run — not the shadowless variant',
            'shadowedBadge' => 'Shadowed · not shadowless',
            'editionNotSet' => 'Edition not set',
            'editionNotSetTitle' => 'This set splits into 1st Edition and Unlimited — this listing states neither',

            'filterBy' => 'Filter %s…',
            'filterByAria' => 'Filter %s options',
            'noMatch' => 'No match',

            'filterSetsByName' => 'Filter sets by name…',
            'inStockOnly' => 'In stock only',
            'setSingular' => '%d set',
            'setPlural' => '%d sets',
            'noSetMatches' => 'No set matches “%s”.',
            'nothingInStock' => 'Nothing is in stock right now.',
            'noSetMatchesInStock' => 'No set matching “%s” is currently in stock. '
                . 'Turn off “In stock only” to see it anyway.',
        ];

        $fr = [
            'inStock' => 'en stock',
            'outOfStock' => 'Rupture de stock',
            'selectVariant' => 'Choisissez une variante',
            'thisPrintingCondition' => 'cette impression et cet état',
            'total' => 'au total',
            'acrossVariant' => 'sur %d variante',
            'acrossVariants' => 'sur %d variantes',

            'exactCardAbove' => 'La photo ci-dessus montre <strong>l’exemplaire exact que vous recevrez</strong>',
            'serial' => 'N° de série',
            'frontAndBack' => 'recto et verso',
            'photoPending' => 'Photo de cet exemplaire à venir — l’image ci-dessus est une numérisation de référence.',
            'soldByCondition' => 'Vendue selon l’état — non photographiée individuellement',
            'soldByConditionBody' => 'Nous ne photographions pas chaque exemplaire de cette carte. L’image '
                . 'ci-dessus est une numérisation de référence; la vôtre est vérifiée à la main selon l’état indiqué.',
            'notPhotographedYet' => 'Exemplaires pas encore photographiés',
            'notPhotographedYetBody' => 'L’image ci-dessus est une numérisation de référence. Nous expédions '
                . 'le plus ancien exemplaire en stock, vérifié à la main selon l’état indiqué.',
            'chooseExactCard' => 'Choisissez votre exemplaire',
            'photographed' => 'photographiés',
            'copyPickerHint' => 'Facultatif — sans sélection, nous expédions le plus ancien exemplaire en stock.',
            'reservedForYou' => 'Réservé pour vous au paiement :',
            'noCopyChosen' => 'Aucun exemplaire choisi — nous expédions le plus ancien en stock.',

            'shadowless' => 'Sans ombre',
            'shadowed' => 'Avec ombre',
            'shadowlessTitle' => 'Tirage « shadowless » — sans ombre portée sous l’illustration',
            'shadowedTitle' => 'Tirage avec ombre — ce n’est pas la variante « shadowless »',
            'shadowedBadge' => 'Avec ombre · pas « shadowless »',
            'editionNotSet' => 'Édition non précisée',
            'editionNotSetTitle' => 'Cette extension se divise en 1st Edition et Unlimited — cette annonce n’indique ni l’une ni l’autre',

            'filterBy' => 'Filtrer %s…',
            'filterByAria' => 'Filtrer les options %s',
            'noMatch' => 'Aucun résultat',

            'filterSetsByName' => 'Filtrer les extensions par nom…',
            'inStockOnly' => 'En stock seulement',
            'setSingular' => '%d extension',
            'setPlural' => '%d extensions',
            'noSetMatches' => 'Aucune extension ne correspond à « %s ».',
            'nothingInStock' => 'Rien n’est en stock pour le moment.',
            'noSetMatchesInStock' => 'Aucune extension correspondant à « %s » n’est en stock. '
                . 'Désactivez « En stock seulement » pour la voir quand même.',
        ];

        $iso = strtolower(substr((string) $this->context->language->iso_code, 0, 2));
        $locale = strtolower((string) ($this->context->language->locale ?? ''));

        // fr-CA installs as iso "qc" in PrestaShop, so check the locale too.
        $isFrench = $iso === 'fr' || $iso === 'qc' || str_starts_with($locale, 'fr');

        return $isFrench ? array_merge($en, $fr) : $en;
    }

    /**
     * Set names whose singles split into 1st Edition and Unlimited print runs.
     *
     * The second high-stakes print-run distinction, and unlike shadowed/shadowless
     * TCGplayer hides it inside one group as SKU subtypes, so it never shows up at
     * set level. Ten groups are affected (audit-editions.php): Base Set
     * (Shadowless), Jungle, Fossil, Team Rocket, Gym Heroes, Gym Challenge and the
     * four Neo sets.
     *
     * Derived from the printings actually attached to our own products rather than
     * a hardcoded list, so a set becomes edition-aware the moment it is stocked.
     *
     * @return array<int,string>
     */
    private function editionSplitSets(): array
    {
        $idLang = (int) $this->context->language->id;

        $rows = Db::getInstance()->executeS(
            // Join through tcg_group_category so only real SET categories qualify.
            // Products are also filed under the "Singles" root, which aggregates
            // every printing in the shop and would otherwise look edition-split.
            'SELECT cl.name
               FROM ' . _DB_PREFIX_ . 'category_product cp
               JOIN ' . _DB_PREFIX_ . 'tcg_group_category tgc
                    ON tgc.id_category = cp.id_category
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = cp.id_category AND cl.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.id_product = cp.id_product
               JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                    ON pac.id_product_attribute = pa.id_product_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = pac.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                    ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $idLang . '
              WHERE agl.name = "Printing"
              GROUP BY cl.name
             HAVING SUM(al.name LIKE "1st Edition%") > 0
                AND SUM(al.name LIKE "Unlimited%") > 0'
        ) ?: [];

        return array_map(static fn ($row) => (string) $row['name'], $rows);
    }
}
