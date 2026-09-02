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

require_once __DIR__ . '/region-codes.php';

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
            && $this->registerHook('displayProductListReviews')
            && $this->registerHook('displayCartExtraProductInfo')
            && $this->registerHook('displayCartExtraProductActions')
            && $this->registerHook('displayHeaderCategory')
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
                /**
                 * The grading companies are Graded's panel entries, shaped
                 * EXACTLY like the category grandchildren Hummingbird already
                 * renders (the eras under a region). A first cut used a made-up
                 * 'link' type and the template hoisted them to bare TOP-LEVEL
                 * menu items - "PSA BGS CGC" beside Pokémon, header wrapped.
                 * Children are also what make Hummingbird wire the panel at all:
                 * a childless rail item gets data-ps-has-child=false and its
                 * hover shows nothing.
                 */
                $nodes[$index]['children'][$childIndex]['children'] = array_map(
                    fn (array $grader) => [
                        'type' => 'category',
                        'page_identifier' => 'category-grader-' . strtolower((string) $grader['name']),
                        'label' => (string) $grader['name'],
                        'url' => $singlesUrl . $glue . 'q=' . rawurlencode($groupName . '-' . $grader['name']),
                        'current' => false,
                        // Hummingbird's template dispatches on the node's DEPTH
                        // FIELD, not its nesting: a node without one matches no
                        // branch and renders as a bare top-level menu item -
                        // that is the "PSA BGS CGC in the navbar" bug, twice.
                        'depth' => 3,
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
     * Localised region category name => short code, for the combined era list.
     *
     * Keyed on the name the MENU actually renders, because that is all the
     * client has to match on; resolved through id_lang 1 so a French storefront
     * still maps "Japonaises" to JP.
     *
     * @return array<string, string>
     */
    private function regionCodes(): array
    {
        $idLang = (int) $this->context->language->id;
        $singlesId = (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
              WHERE cl.id_lang = 1 AND cl.name = "Singles"'
        );
        if (!$singlesId) {
            return [];
        }

        $codes = [];
        foreach (Db::getInstance()->executeS(
            'SELECT en.name AS en, loc.name AS loc
               FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_lang en
                    ON en.id_category = c.id_category AND en.id_lang = 1 AND en.id_shop = 1
               JOIN ' . _DB_PREFIX_ . 'category_lang loc
                    ON loc.id_category = c.id_category AND loc.id_lang = ' . $idLang . ' AND loc.id_shop = 1
              WHERE c.id_parent = ' . $singlesId . ' AND c.active = 1'
        ) ?: [] as $row) {
            $codes[(string) $row['loc']] = REGION_CODE[(string) $row['en']] ?? '';
        }

        return $codes;
    }

    /**
     * Region and grader shortcut strips for the Sealed and Graded flyout panels.
     *
     * Under Singles the regions are CATEGORIES, so its panel gets real tabs that
     * switch the era grid. Under Sealed and Graded region is a FACET (see
     * lib/region.php for why), so their panels get the same-looking strip whose
     * tabs are simply filter links - western booster boxes, japanese slabs -
     * built server-side because every part of a facet URL is localised: the
     * facet name (Region / Région), the value (Western / Occidentale) and the
     * category link itself.
     *
     * @return array{sealed: array, graded: array}
     */
    private function sectionStrips(): array
    {
        $idLang = (int) $this->context->language->id;
        $db = Db::getInstance();

        $catId = static fn (string $name): int => (int) $db->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
               JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cl.id_category AND c.active = 1
              WHERE cl.id_lang = 1 AND cl.name = "' . pSQL($name) . '"'
        );
        $sealedId = $catId('Sealed');
        $singlesId = $catId('Singles');
        if (!$sealedId || !$singlesId) {
            return ['sealed' => [], 'graded' => []];
        }

        // Localised facet vocabulary. The Region facet is a feature, Grading an
        // attribute group; both carry their display names per language.
        $regionFacet = (string) $db->getValue(
            'SELECT fl.name FROM ' . _DB_PREFIX_ . 'feature_lang fl
               JOIN ' . _DB_PREFIX_ . 'feature_lang en ON en.id_feature = fl.id_feature
                    AND en.id_lang = 1 AND en.name = "Region"
              WHERE fl.id_lang = ' . $idLang
        );
        $gradingFacet = (string) $db->getValue(
            'SELECT public_name FROM ' . _DB_PREFIX_ . 'attribute_group_lang
              WHERE id_lang = ' . $idLang . ' AND name IN ("Grading", "Gradation")'
        );

        // Region values that actually hold stock, with their localised labels.
        $regions = $db->executeS(
            'SELECT DISTINCT loc.value AS label, en.value AS en
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = fp.id_feature
                    AND fl.id_lang = 1 AND fl.name = "Region"
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang en
                    ON en.id_feature_value = fp.id_feature_value AND en.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang loc
                    ON loc.id_feature_value = fp.id_feature_value AND loc.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'stock_available sa ON sa.id_product = fp.id_product AND sa.quantity > 0'
        ) ?: [];

        $stockedGraders = $db->executeS(
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
        $graderList = implode('-', array_column($stockedGraders, 'name'));

        /**
         * The region CATEGORIES under Singles, keyed by English name.
         *
         * Region is a category on this branch and a facet elsewhere, so the two
         * sections express the same choice differently: Sealed filters, Graded
         * navigates. Keyed at id_lang 1 because the localised names diverge -
         * the category is "Occidentales" (agreeing with cartes) while the
         * feature value is "Occidentale" (agreeing with région), so matching on
         * the French string finds nothing.
         */
        $regionCategories = [];
        foreach ($db->executeS(
            'SELECT c.id_category, en.name
               FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_lang en
                    ON en.id_category = c.id_category AND en.id_lang = 1 AND en.id_shop = 1
              WHERE c.id_parent = ' . $singlesId . ' AND c.active = 1'
        ) ?: [] as $row) {
            $regionCategories[(string) $row['name']] = (int) $row['id_category'];
        }

        $sealedUrl = $this->context->link->getCategoryLink($sealedId);
        $singlesUrl = $this->context->link->getCategoryLink($singlesId);
        $join = static fn (string $url, string $q): string => $url . (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode($q);

        $all = $this->context->language->iso_code === 'en' ? 'All' : 'Tout';

        // Each tab carries its raw region query too: hovering a tab RESCOPES the
        // panel's entry links (booster boxes -> western booster boxes), the same
        // switching feel the Singles panel has.
        $sealedTabs = [['label' => $all, 'url' => $sealedUrl, 'query' => '']];
        $gradedTabs = $graderList === '' ? [] : [['label' => $all, 'url' => $join($singlesUrl, $gradingFacet . '-' . $graderList), 'query' => '']];
        foreach ($regions as $region) {
            $regionQuery = $regionFacet . '-' . $region['label'];
            $sealedTabs[] = ['label' => (string) $region['label'],
                'url' => $join($sealedUrl, $regionQuery), 'query' => $regionQuery];
            if ($graderList !== '') {
                /**
                 * NAVIGATE into the region, do not filter Singles by it.
                 *
                 * Picking "Graded > Western" used to land on Singles carrying a
                 * Region facet - so the page you arrived at still offered
                 * Western and Japanese as choices you had just made. Going to
                 * the region category instead makes the choice structural: the
                 * breadcrumb reads Singles / Western and the cards on arrival
                 * are that region's eras.
                 */
                $regionCategoryId = $regionCategories[(string) $region['en']] ?? null;
                $gradedTabs[] = ['label' => (string) $region['label'],
                    'url' => $regionCategoryId !== null
                        ? $join($this->context->link->getCategoryLink($regionCategoryId), $gradingFacet . '-' . $graderList)
                        : $join($singlesUrl, $gradingFacet . '-' . $graderList . '/' . $regionQuery),
                    'query' => $regionQuery];
            }
        }

        /**
         * The game root's Categories facet lists Singles and Sealed - real child
         * categories with stock - but Graded cannot appear there because its
         * category is empty by design. The client injects this as a synthetic
         * option so the root page filters by all three forms.
         */
        $gradedFacetOption = null;
        if ($graderList !== '') {
            $gradedName = (string) $db->getValue(
                'SELECT cl.name FROM ' . _DB_PREFIX_ . 'category_lang cl
                   JOIN ' . _DB_PREFIX_ . 'category_lang en ON en.id_category = cl.id_category
                        AND en.id_lang = 1 AND en.name = "Graded"
                  WHERE cl.id_lang = ' . $idLang . ' AND cl.id_shop = 1'
            );
            $gradedFacetOption = ['label' => $gradedName ?: 'Graded',
                'query' => $gradingFacet . '-' . $graderList];
        }

        $controller = $this->context->controller;
        $isGameRoot = $controller instanceof CategoryController
            && Validate::isLoadedObject($controller->getCategory())
            && (int) $controller->getCategory()->id === $this->pokemonCategoryId();

        return [
            'sealed' => ['tabs' => $sealedTabs],
            'graded' => ['tabs' => $gradedTabs],
            'gradedFacetOption' => $gradedFacetOption,
            'isGameRoot' => $isGameRoot,
        ];
    }



    private function gradedFilterUrl(): string
    {
        $db = Db::getInstance();
        $singlesId = (int) $db->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
              WHERE cl.id_lang = 1 AND cl.name = "Singles"'
        );
        $url = $this->context->link->getCategoryLink($singlesId);
        $grading = (string) $db->getValue(
            'SELECT public_name FROM ' . _DB_PREFIX_ . 'attribute_group_lang
              WHERE id_lang = ' . (int) $this->context->language->id . ' AND name IN ("Grading", "Gradation")'
        );
        $stocked = $db->executeS(
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
        $list = implode('-', array_column($stocked, 'name'));

        return $list === '' ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode($grading . '-' . $list);
    }


    /**
     * Splits a faceted-search `q` into [facetName => "Value-Value"] blocks.
     *
     * PrestaShop's encoding is `Facet-Value-Value/Facet2-Value`: blocks joined
     * by "/", and inside a block the facet name then its values, all joined by
     * "-". Names are matched to the FIRST hyphen, which is the same assumption
     * the module itself makes.
     *
     * @return array<string, string>
     */
    private function parseFacetQuery(string $query): array
    {
        $out = [];
        foreach (explode('/', $query) as $block) {
            $block = trim($block);
            if ($block === '' || !str_contains($block, '-')) {
                continue;
            }
            [$name, $values] = explode('-', $block, 2);
            $out[$name] = $values;
        }

        return $out;
    }

    /**
     * Carries the shopper's active filters onto a link that moves them down the
     * category tree, dropping only the facets that link ITSELF expresses.
     *
     * Entering Graded and then picking Western used to silently drop the whole
     * reason you were there: the section cards were plain category links, so a
     * deliberate choice was thrown away by the next click. Now every card keeps
     * the query, so the filter survives going several levels deep into sets and
     * back out again.
     *
     * What gets dropped is decided by the cards themselves, not a hardcoded list:
     * a facet whose selected values name the sibling cards IS those cards (the
     * Region facet beside Western/Japanese cards, the Set facet beside set
     * cards), so carrying it would contradict the click - picking Japanese while
     * Region=Western is still applied yields an empty page. `$owned` covers the
     * one card that expresses a facet without sharing its vocabulary: Graded,
     * whose card is the Grading filter.
     *
     * @param array<int, string> $siblingNames names of every card in this row
     * @param array<int, string> $owned        facet names a card explicitly is
     */
    private function carryFilters(string $url, array $siblingNames, array $owned = []): string
    {
        $active = $this->parseFacetQuery((string) Tools::getValue('q', ''));
        if ($active === []) {
            return $url;
        }

        $lowerNames = array_map('mb_strtolower', $siblingNames);
        $keep = [];
        foreach ($active as $facet => $values) {
            if (in_array($facet, $owned, true)) {
                continue;
            }
            $expressesSiblings = false;
            foreach (explode('-', $values) as $value) {
                if (in_array(mb_strtolower($value), $lowerNames, true)) {
                    $expressesSiblings = true;
                    break;
                }
            }
            if (!$expressesSiblings) {
                $keep[] = $facet . '-' . $values;
            }
        }
        if ($keep === []) {
            return $url;
        }

        // The Graded card arrives already carrying its own q; merge into it
        // rather than starting a second one.
        if (preg_match('/[?&]q=([^&]*)/', $url, $existing)) {
            $merged = rawurldecode($existing[1]) . '/' . implode('/', $keep);

            return str_replace($existing[0], substr($existing[0], 0, 3) . rawurlencode($merged), $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode(implode('/', $keep));
    }

    /**
     * The cards that head a browse page, replacing the subcategory chip wall.
     *
     * A category's children render as art tiles (the homepage treatment): the
     * Pokémon page gets Singles / Sealed / Graded, Singles gets the regions, an
     * era gets its sets in a scrollable row. Art comes from whatever the child
     * actually owns - its nav artwork or its category image (the set logos) -
     * and a child with neither renders as a clean name-only card, which is where
     * the Japanese sets sit until their logos are sourced.
     *
     * @return array{title: string, cards: array<int, array>}|null
     */
    /**
     * The subcategory card row, rendered with the page rather than after it.
     *
     * This row replaces the header's title and its wall of subcategory chips.
     * Built in JavaScript, that replacement happened a beat after the page had
     * drawn, so every arrival at a category flashed the plain chips before they
     * were swapped for the cards - which is what a shopper actually notices,
     * because the two look nothing alike.
     *
     * The markup is the server's; the arrows and the drag-to-scroll are still
     * wired by the client, because those are behaviour and behaviour has
     * nothing to show.
     */
    public function hookDisplayHeaderCategory(): string
    {
        $data = $this->categoryCards();
        if ($data === null || ($data['cards'] ?? []) === []) {
            return '';
        }

        $cards = '';
        foreach ($data['cards'] as $card) {
            $art = (string) ($card['art'] ?? '');
            $cards .= '<a class="cc-scard' . ($art === '' ? ' cc-scard--plain' : '') . '"'
                . ' href="' . htmlspecialchars((string) $card['url'], ENT_QUOTES, 'UTF-8') . '"'
                . ($art === ''
                    ? ''
                    : ' style="--cc-scard-art:url(&quot;' . htmlspecialchars($art, ENT_QUOTES, 'UTF-8') . '&quot;)"')
                . '><span class="cc-scard__name">'
                . htmlspecialchars((string) $card['name'], ENT_QUOTES, 'UTF-8')
                . '</span></a>';
        }

        /**
         * The arrows ship inert and are given behaviour on wiring. Rendering
         * them here keeps the row a fixed height from the first paint, so the
         * page does not shift when the client catches up.
         */
        return '<div class="cc-scards">'
            . '<div class="cc-scards__row">' . $cards . '</div>'
            . '<button type="button" class="cc-scards__arrow cc-scards__arrow--prev"'
            . ' aria-label="Scroll back">&#8249;</button>'
            . '<button type="button" class="cc-scards__arrow cc-scards__arrow--next"'
            . ' aria-label="Scroll forward">&#8250;</button>'
            . '</div>';
    }

    private function categoryCards(): ?array
    {
        $controller = $this->context->controller;
        if (!$controller instanceof CategoryController) {
            return null;
        }
        $category = $controller->getCategory();
        if (!Validate::isLoadedObject($category)) {
            return null;
        }
        $idLang = (int) $this->context->language->id;

        $children = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name
               FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = 1
              WHERE c.id_parent = ' . (int) $category->id . ' AND c.active = 1
              ORDER BY c.position'
        ) ?: [];
        if ($children === []) {
            return null;
        }

        // Same rule as the menu: a card that leads to an empty page is a click
        // into disappointment. The Japanese Scarlet & Violet era holds 64 set
        // categories and stock in three of them.
        $stocked = $this->stockedCategories();

        // Graded's category is empty BY DESIGN - slabs are combinations on the
        // cards' own listings - but it is still a section a shopper enters, so
        // its card survives the stock filter and points at the graded filter,
        // exactly as the nav rail entry does.
        $gradedId = (int) Db::getInstance()->getValue(
            'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
              WHERE cl.id_lang = 1 AND cl.name = "Graded"'
        );

        // Every card in the row, so each link knows which facets it expresses.
        $siblingNames = [];
        foreach ($children as $child) {
            $isGraded = (int) $child['id_category'] === $gradedId;
            if ($isGraded || isset($stocked[(int) $child['id_category']])) {
                $siblingNames[] = (string) $child['name'];
            }
        }
        $gradingFacet = (string) Db::getInstance()->getValue(
            'SELECT public_name FROM ' . _DB_PREFIX_ . 'attribute_group_lang
              WHERE id_lang = ' . $idLang . ' AND name IN ("Grading", "Gradation")'
        );

        $cards = [];
        foreach ($children as $child) {
            $isGraded = (int) $child['id_category'] === $gradedId;
            if (!$isGraded && !isset($stocked[(int) $child['id_category']])) {
                continue;
            }
            $id = (int) $child['id_category'];
            $art = null;
            if (is_file(_PS_IMG_DIR_ . 'nav/' . $id . '.png')) {
                $art = _PS_IMG_ . 'nav/' . $id . '.png';
            } elseif (is_file(_PS_CAT_IMG_DIR_ . $id . '.jpg')) {
                $art = _PS_IMG_ . 'c/' . $id . '.jpg';
            }
            $cards[] = [
                'name' => (string) $child['name'],
                'url' => $this->carryFilters(
                    $isGraded ? $this->gradedFilterUrl() : $this->context->link->getCategoryLink($id),
                    $siblingNames,
                    // The Graded card IS the grading filter, so it must not also
                    // inherit the one already applied - that is how you end up
                    // with Grading listed twice in the same query.
                    $isGraded && $gradingFacet !== '' ? [$gradingFacet] : []
                ),
                'art' => $art,
            ];
        }

        return ['title' => (string) $category->name, 'cards' => $cards];
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
            'cryptocardsGradings' => $this->gradingMap(),
            'cryptocardsLanguageFacet' => $this->languageFacet(),
            'cryptocardsCartChips' => $this->cartChips(),
            'cryptocardsCartLines' => $this->cartLines(),
            /**
             * Emitted on EVERY page, not just the product page.
             *
             * These were part of the product-only payload, which is where the
             * picker was first built. The cart needs them too - it offers the
             * same picker when a quantity goes up - and on that page they were
             * simply undefined, so the modal returned at its first line and the
             * confirm silently recorded nothing. A URL is not product state.
             */
            'cryptocardsCopiesUrl' => $this->context->link->getModuleLink(
                $this->name, 'copies', [], true
            ),
            // Owned by the copies module, which owns the choice table.
            'cryptocardsChooseUrl' => $this->context->link->getModuleLink(
                'cryptocards_copies', 'choose', [], true
            ),
            'cryptocardsPrintingsById' => $this->printingsById(),
            'cryptocardsConditionsById' => $this->conditionsById(),
            'cryptocardsLanguages' => $this->languageMap(),
            'cryptocardsLanguagesById' => $this->languagesById(),
            'cryptocardsStats' => $this->homepageStats(),
            'cryptocardsNavImages' => $this->navImages(),
            'cryptocardsSectionStrips' => $this->sectionStrips(),
            'cryptocardsRegionCodes' => $this->regionCodes(),
            'cryptocardsCategoryCards' => $this->categoryCards(),
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
            'cryptocardsGallery' => $this->galleryContext($productId),
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

        /**
         * A product with no combinations is its own single SKU, id 0.
         *
         * Sealed product has no variant axes at all, so the loop above produces
         * nothing, every signature lookup misses, and the panel fell back to
         * "Select a variant … across 0 variants" - asking the shopper to choose
         * between no options. It also left the serialised-copy panel unable to
         * find its SKU, which is where sealed stock actually lives now that boxes
         * are serialised too. The empty signature is exactly what the client
         * derives when there are no selectors to read.
         */
        if ($rows === []) {
            $variants[''] = $total;
            $signatureToSku[''] = '0';
        }

        return [
            'total' => $total,
            'variants' => $variants,
            'signatureToSku' => $signatureToSku,
            // Counts real variant axes, so 0 still means "this product has none"
            // and the storefront can word the stock line accordingly.
            'skuCount' => count($rows),
        ];
    }

    /**
     * The product's stock photos that belong to no single combination.
     *
     * For a card that is the front scan and the card back - the images that are
     * true of the PRINTING however you buy it. The slab composites are excluded
     * because each one is wired to its own combination.
     *
     * Emitted as bare image ids, not URLs. The gallery markup already carries
     * correctly sized srcsets, so the client appends by cloning a slide and
     * swapping the id in its URLs; sending URLs would mean naming image types
     * here and rebuilding the responsive set by hand.
     *
     * Needed because selecting a graded SKU makes PrestaShop filter the gallery
     * down to that combination's single image, leaving a slab listing with no
     * picture of the card's front or back at all.
     *
     * @return array{base: array<int, int>}
     */
    private function galleryContext(int $productId): array
    {
        $wired = [];
        foreach (Db::getInstance()->executeS(
            'SELECT DISTINCT pai.id_image
               FROM ' . _DB_PREFIX_ . 'product_attribute_image pai
               JOIN ' . _DB_PREFIX_ . 'image i ON i.id_image = pai.id_image
              WHERE i.id_product = ' . $productId
        ) ?: [] as $row) {
            $wired[(int) $row['id_image']] = true;
        }

        $base = [];
        foreach (Db::getInstance()->executeS(
            'SELECT id_image FROM ' . _DB_PREFIX_ . 'image
              WHERE id_product = ' . $productId . '
              ORDER BY cover DESC, position ASC'
        ) ?: [] as $row) {
            $id = (int) $row['id_image'];
            if (!isset($wired[$id])) {
                $base[] = $id;
            }
        }

        return ['base' => $base];
    }

    /**
     * Serialised copies for this product, grouped by SKU.
     *
     * Drives the photography rule in docs/operations-pipeline.md §2.4:
     *
     *   any available + photos  -> stock photo, plus "choose your exact card"
     *   no photo captured yet   -> stock photo, and say photography is pending
     *   policy = stock_only     -> stock photo, and say so plainly (bulk)
     *
     * The stock photo ALWAYS holds the gallery - on the product page and in the
     * browser tile - until the shopper picks a serial for themselves. Two earlier
     * rules are gone: sealed is serialised now (a box's condition varies even if
     * the cards inside do not), and a lone copy no longer promotes its own
     * photograph over the stock image, which had the effect of replacing a
     * composited slab with a snapshot nobody asked for.
     *
     * The last two rules are separate on purpose. "Not shot yet" and "never going
     * to be shot" look identical in the data but mean opposite things to a buyer,
     * so the panel always renders and always states which one applies - silence
     * reads as an omission.
     */
    /** How many copy tiles ship with the page; the rest are paged in on scroll. */
    public const COPY_PAGE = 24;

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
            $withPhotos = array_values(array_filter($data['copies'], static fn ($copy) => $copy['captured']));

            /**
             * Counts describe the WHOLE SKU; the copy list is only its first page.
             *
             * A popular card can hold fifty photographed copies, each carrying four
             * photo URLs, and embedding all of them put the entire set into every
             * product page whether or not anyone opened the picker. The rest are
             * fetched from the copies controller as the carousel is dragged, so
             * these totals must still be computed before the list is trimmed -
             * `count` is the ceiling on how many can be selected, and `photographed`
             * is what the badge reports.
             */
            // Carried so the carousel can ask the copies controller for its own
            // next page without the client having to reconstruct which SKU it is.
            $skus[$skuId]['id'] = (int) $skuId;
            $skus[$skuId]['idProduct'] = $productId;
            $skus[$skuId]['count'] = count($data['copies']);
            $skus[$skuId]['photographed'] = count($withPhotos);
            // A SKU counts as stock-only when every copy in it is flagged that way.
            // One copy still queued for the camera means the SKU is pending, not
            // abandoned - so the buyer is never told "no photos" while one is coming.
            $stockOnly = array_filter($data['copies'], static fn ($copy) => $copy['policy'] === 'stock_only');
            $skus[$skuId]['policy'] = count($stockOnly) === count($data['copies']) ? 'stock_only' : 'per_copy';

            /**
             * Only photographed copies become tiles, so only they are worth
             * sending. When none are photographed the panel still needs one
             * copy - it prints that serial and says the photo is pending.
             */
            $skus[$skuId]['copies'] = $withPhotos !== []
                ? array_slice($withPhotos, 0, self::COPY_PAGE)
                : array_slice($data['copies'], 0, 1);
        }

        return ['serialised' => true, 'skus' => $skus, 'pageSize' => self::COPY_PAGE];
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
    /**
     * The cart line's chips and its "show selected cards" button, rendered by
     * the server with the line they belong to.
     *
     * These used to be injected by JavaScript after the page had loaded, which
     * meant every arrival at the cart - and every refresh - showed PrestaShop's
     * bare line first and the decorated one a moment later. Nothing here depends
     * on anything the client knows, so nothing here has any business waiting for
     * the client. It also means the AJAX re-render brings them back by itself:
     * the cart block comes from the server already complete.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayCartExtraProductInfo(array $params): string
    {
        $chips = $this->cartChips()[$this->cartLineKey($params)] ?? [];
        if ($chips === []) {
            return '';
        }

        $html = '';
        foreach ($chips as $chip) {
            $html .= '<span class="cc-chip ' . htmlspecialchars((string) $chip['cls'], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars((string) $chip['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return '<div class="cc-chips cc-chips--line">' . $html . '</div>';
    }

    /** @param array<string, mixed> $params */
    public function hookDisplayCartExtraProductActions(array $params): string
    {
        $product = $params['product'] ?? null;
        $line = $this->cartLines()[(string) (int) ($product['id_product_attribute'] ?? 0)] ?? null;
        $chosen = $line['chosen'] ?? [];
        if ($chosen === []) {
            return '';
        }

        return '<button type="button" class="cc-cart-copies__btn">'
            . htmlspecialchars(
                ($this->translations()['showSelectedCards'] ?? 'Show selected cards')
                . ' (' . count($chosen) . ')',
                ENT_QUOTES,
                'UTF-8'
            )
            . '</button>';
    }

    /**
     * How a cart line is addressed in the chip map: by SKU where it has one,
     * by product where it does not. Sealed lines all report attribute id 0.
     *
     * @param array<string, mixed> $params
     */
    private function cartLineKey(array $params): string
    {
        $product = $params['product'] ?? null;
        $skuId = (int) ($product['id_product_attribute'] ?? 0);

        return $skuId > 0 ? (string) $skuId : 'p' . (int) ($product['id_product'] ?? 0);
    }

    /** @var array<string, mixed>|null */
    private $cartChipsCache = null;

    /**
     * Built once per request. It is read for the page-level payload and again
     * for every line the hooks above render.
     */
    private function cartChips(): array
    {
        if ($this->cartChipsCache === null) {
            $this->cartChipsCache = $this->buildCartChips();
        }

        return $this->cartChipsCache;
    }

    private function buildCartChips(): array
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
        /**
         * A cart of nothing but sealed product still has chips to draw.
         *
         * Sealed has no combination, so its lines carry attribute id 0 - which
         * this dropped along with the empty set, and then bailed out entirely.
         * The product ids are what the pass really needs; the SKU ids only
         * matter for the lines that have one.
         */
        $skuIds = array_values(array_filter(array_unique($skuIds)));
        if ($productIds === []) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $conditions = $this->conditionMap();
        $printings = $this->printingMap();
        $gradings = $this->gradingMap();
        $printRuns = $this->printRunSets();

        // Condition and Printing come off the combination; groups are matched on
        // their ENGLISH name because the labels are translated.
        $bySku = [];
        $skuRows = $skuIds === [] ? [] : (Db::getInstance()->executeS(
            'SELECT pac.id_product_attribute, en.name AS grp, al.name AS value
               FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                    ON en.id_attribute_group = a.id_attribute_group AND en.id_lang = 1
              WHERE pac.id_product_attribute IN (' . implode(',', $skuIds) . ')'
        ) ?: []);
        foreach ($skuRows as $row) {
            $bySku[(int) $row['id_product_attribute']][(string) $row['grp']] = (string) $row['value'];
        }

        // Rarity, Card Language and the set, per product.
        $byProduct = [];
        foreach (Db::getInstance()->executeS(
            'SELECT fp.id_product, en.name AS feature, fvl.value
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang en
                    ON en.id_feature = fp.id_feature AND en.id_lang = 1
                   AND en.name IN ("Rarity", "Card Language")
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

            /**
             * The grader leads the pair it forms with the condition.
             *
             * Cart lines are keyed on the combination, so a raw copy and a PSA 9 of
             * one card are already two separate lines - but without this chip they
             * were two lines reading "Charizard - Near Mint" and "Charizard - 9",
             * distinguishable only by price. A tier number alone says nothing: a 9.5
             * is a different market at Beckett than at CGC.
             */
            $grading = $bySku[$skuId]['Grading'] ?? '';
            if ($grading !== '') {
                $slug = $this->anchorSlug($grading);
                if (!($gradings[$slug]['skip'] ?? false)) {
                    $chips[] = ['label' => $grading, 'cls' => 'cc-chip--grading'];
                }
            }

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
                    'label' => $this->translations()[$run === 'shadowless' ? 'shadowless' : 'shadowed'] ?? $run,
                    'cls' => 'cc-chip--run cc-chip--' . $run,
                ];
            }

            $rarity = $byProduct[$productId]['Rarity'] ?? '';
            if ($rarity !== '') {
                $chips[] = ['label' => $rarity, 'cls' => 'cc-chip--rarity'];
            }

            /**
             * From the COMBINATION for a card, from the PRODUCT for sealed.
             *
             * A card product can hold several languages at once, so language is
             * a SKU axis there. Sealed has no variants - an Elite Trainer Box is
             * English or it is Japanese, and the Japanese one is a different
             * product at a different price - so it carries the fact as a
             * feature. One question, one chip, read from wherever that product
             * type keeps the answer.
             */
            $language = $bySku[$skuId]['Card Language']
                ?? $byProduct[$productId]['Card Language']
                ?? '';
            if ($language !== '') {
                $chips[] = ['label' => $language, 'cls' => 'cc-chip--language'];
            }

            if ($chips !== []) {
                // Sealed lines all share attribute id 0, so keying on it alone
                // would hand every sealed line the first one's chips.
                $out[$skuId > 0 ? (string) $skuId : 'p' . $productId] = $this->foldGradeChips($chips);
            }
        }

        return $out;
    }

    /**
     * Per cart line: the copies already chosen, and how many more could be.
     *
     * Three cart behaviours need the same three facts, so they are gathered once:
     *
     *   - "Show selected cards" lists the serials chosen for that line
     *   - the quantity control is capped at what is actually in stock
     *   - raising the quantity offers a picker, but only where photos exist
     *
     * Keyed by id_product_attribute, which is also what keys a cart line, so the
     * client can match a line to its data without another lookup.
     *
     * @return array<string, array{idProduct:int, stock:int, photographed:int, chosen:array}>
     */
    /** @var array<string, mixed>|null */
    private $cartLinesCache = null;

    /** Built once per request: the payload reads it, and so does every line. */
    private function cartLines(): array
    {
        if ($this->cartLinesCache !== null) {
            return $this->cartLinesCache;
        }
        $this->cartLinesCache = $this->buildCartLines();

        return $this->cartLinesCache;
    }

    private function buildCartLines(): array
    {
        if (!in_array($this->context->controller->php_self, ['cart', 'order'], true)) {
            return [];
        }
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return [];
        }

        $out = [];
        foreach ($cart->getProducts() as $product) {
            $productId = (int) $product['id_product'];
            $skuId = (int) $product['id_product_attribute'];

            /**
             * Availability counts copies, not stock_available.
             *
             * They agree by construction - copies-schema proves the invariant on
             * every run - but the copy rows are what a chosen serial points at, so
             * counting them keeps the ceiling and the picker describing the same
             * set of physical cards.
             */
            $stock = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy
                  WHERE id_product = ' . $productId . ' AND id_product_attribute = ' . $skuId . '
                    AND status IN ("available", "reserved")'
            );
            $photographed = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy c
                  WHERE c.id_product = ' . $productId . ' AND c.id_product_attribute = ' . $skuId . '
                    AND c.status = "available"
                    AND EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'card_copy_image ci
                                 WHERE ci.id_copy = c.id_copy)'
            );

            // The serials this cart asked for on this line, with a thumbnail each.
            /**
             * Every shot of every chosen copy, not just a thumbnail.
             *
             * The cart's modals show the card being bought, and a shopper
             * checking their order wants the same views they had on the product
             * page - front, back and details - not a single tile.
             */
            $chosen = [];
            foreach (Db::getInstance()->executeS(
                'SELECT ch.copy_uid, ci.filename, ci.side
                   FROM ' . _DB_PREFIX_ . 'card_copy_choice ch
                   LEFT JOIN ' . _DB_PREFIX_ . 'card_copy c2 ON c2.copy_uid = ch.copy_uid
                   LEFT JOIN ' . _DB_PREFIX_ . 'card_copy_image ci ON ci.id_copy = c2.id_copy
                  WHERE ch.id_cart = ' . (int) $cart->id . '
                    AND ch.id_product = ' . $productId . '
                    AND ch.id_product_attribute = ' . $skuId . '
                  ORDER BY ch.date_add, ch.copy_uid, ci.position'
            ) ?: [] as $row) {
                $uid = (string) $row['copy_uid'];
                if (!isset($chosen[$uid])) {
                    $chosen[$uid] = ['uid' => $uid, 'image' => null, 'photos' => []];
                }
                if (!$row['filename']) {
                    continue;
                }
                $url = __PS_BASE_URI__ . 'img/cc-copies/' . rawurlencode((string) $row['filename']);
                $chosen[$uid]['photos'][] = ['url' => $url, 'side' => (string) $row['side']];
                if ($chosen[$uid]['image'] === null) {
                    $chosen[$uid]['image'] = $url;
                }
            }
            $chosen = array_values($chosen);

            /**
             * A line can never have more chosen copies than it is buying.
             *
             * Lowering a quantity used to leave the choices untouched, so a line
             * of two could still claim three chosen cards - and the surplus would
             * have been honoured at checkout, reserving a card the shopper had
             * already given up. The extras are dropped here rather than merely
             * hidden, so the stored intent matches what is displayed.
             */
            $lineQuantity = (int) $product['cart_quantity'];
            if (count($chosen) > $lineQuantity) {
                foreach (array_slice($chosen, $lineQuantity) as $surplus) {
                    Db::getInstance()->execute(
                        'DELETE FROM ' . _DB_PREFIX_ . 'card_copy_choice
                          WHERE id_cart = ' . (int) $cart->id . '
                            AND id_product = ' . $productId . '
                            AND id_product_attribute = ' . $skuId . '
                            AND copy_uid = "' . pSQL((string) $surplus['uid']) . '"'
                    );
                }
                $chosen = array_slice($chosen, 0, $lineQuantity);
            }

            $out[(string) $skuId] = [
                'idProduct' => $productId,
                'stock' => $stock,
                'photographed' => $photographed,
                'quantity' => $lineQuantity,
                'chosen' => $chosen,
            ];
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
     * (ops/audits/audit-parallel-sets.php): Base Set is the only such pair, but
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
     * Grader slug => label, plus whether the chip should be suppressed.
     *
     * "Ungraded" is the absence of a grader, so it earns no chip - the same rule
     * "Normal" gets among the printings, and decided the same way, against the
     * ENGLISH name. A raw card on the French storefront must not sprout a "Non
     * gradée" chip that its English twin does not have.
     *
     * The label is taken verbatim rather than title-cased: every grader is an
     * acronym, and title-casing turns PSA into "Psa".
     *
     * @return array<string, array{label: string, skip: bool}>
     */
    private function gradingMap(): array
    {
        $idLang = (int) $this->context->language->id;

        $rows = Db::getInstance()->executeS(
            'SELECT al.name, en.name AS english
               FROM ' . _DB_PREFIX_ . 'attribute a
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_lang en
                    ON en.id_attribute = a.id_attribute AND en.id_lang = 1
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang grp
                    ON grp.id_attribute_group = a.id_attribute_group AND grp.id_lang = 1
              WHERE grp.name = "Grading"
              ORDER BY a.position'
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $map[$this->anchorSlug($name)] = [
                'label' => $name,
                'skip' => strcasecmp((string) $row['english'], 'Ungraded') === 0,
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
            'SELECT c.id_category, cl.name FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = c.id_category AND cl.id_lang = 1 AND cl.id_shop = 1
               JOIN ' . _DB_PREFIX_ . 'category region
                    ON region.id_category = c.id_parent
               JOIN ' . _DB_PREFIX_ . 'category_lang singles
                    ON singles.id_category = region.id_parent AND singles.id_lang = 1
              WHERE singles.name = "Singles" AND c.active = 1'
        ) ?: [] as $row) {
            $eras[(int) $row['id_category']] = (string) $row['name'];
        }

        $out = [];
        $byName = [];
        foreach (glob($directory . '*.png') ?: [] as $file) {
            $key = (int) basename($file, '.png');
            if (isset($eras[$key])) {
                $out[(string) $key] = _PS_IMG_ . 'nav/' . basename($file);
                $byName[$eras[$key]] = $out[(string) $key];
            }
        }

        /**
         * Same-name fallback across regions. The generation blocks ARE the same
         * brand on both sides of the Pacific - the Japanese Scarlet & Violet
         * block wears the same wordmark as the Western era - so a Japanese era
         * without its own artwork borrows the Western era's by English name.
         * Japan-only blocks (ADV, PCG, LEGEND) simply stay plain until artwork
         * for them is sourced.
         */
        foreach ($eras as $id => $name) {
            if (!isset($out[(string) $id]) && isset($byName[$name])) {
                $out[(string) $id] = $byName[$name];
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

    /**
     * The language chip for a tile that has no variants to read one from.
     *
     * A card tile derives its chips client-side from the variant fragment in its
     * own URL, because which variant is on offer is a client-side choice. Sealed
     * has no variants: its language is a settled fact about the product, so it
     * is rendered here, by the server, with the tile it belongs to.
     *
     * That also survives the listing refresh. Anything shipped as a page-level
     * payload goes stale the moment a sort or a facet re-renders the grid over
     * AJAX - the new tiles arrive from the server and the payload does not.
     *
     * The hook is the theme's only per-product slot that is a direct child of
     * the tile's info column; reviews are simply what usually sits in it. The
     * alternative slots are nested inside the price block, which is the wrong
     * place for a fact about the product.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayProductListReviews(array $params): string
    {
        $product = $params['product'] ?? null;
        if ((int) ($product['id_product'] ?? 0) <= 0) {
            return '';
        }

        $chips = (int) ($product['id_product_attribute'] ?? 0) > 0
            ? $this->variantTileChips($product)
            : $this->sealedTileChips($product);
        if ($chips === []) {
            return '';
        }

        $html = '';
        foreach ($chips as $chip) {
            $html .= '<span class="cc-chip ' . htmlspecialchars((string) $chip['cls'], ENT_QUOTES, 'UTF-8') . '"'
                . (empty($chip['edition'])
                    ? ''
                    : ' data-edition="' . htmlspecialchars((string) $chip['edition'], ENT_QUOTES, 'UTF-8') . '"')
                . (empty($chip['title'])
                    ? ''
                    : ' title="' . htmlspecialchars((string) $chip['title'], ENT_QUOTES, 'UTF-8') . '"')
                . '>' . htmlspecialchars((string) $chip['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return '<div class="cc-chips cc-chips--tile">' . $html . '</div>';
    }

    /** Sealed and anything else sold as a single SKU: the language, and nothing else. */
    private function sealedTileChips($product): array
    {
        $idLang = (int) $this->context->language->id;
        $language = (string) Db::getInstance()->getValue(
            'SELECT fvl.value
               FROM ' . _DB_PREFIX_ . 'feature_product fp
               JOIN ' . _DB_PREFIX_ . 'feature_lang en
                    ON en.id_feature = fp.id_feature AND en.id_lang = 1
                   AND en.name = "Card Language"
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang . '
              WHERE fp.id_product = ' . (int) $product['id_product'] . '
                AND NOT EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute pa
                                 WHERE pa.id_product = fp.id_product)'
        );

        return $language === ''
            ? []
            : [['label' => $language, 'cls' => 'cc-chip--language']];
    }

    /**
     * The words a grade tier uses, in the short forms the hobby actually writes.
     *
     * A slab is referred to as "PSA 10", not as a grading company and a
     * condition standing side by side, and the qualifier is written short on the
     * label itself - PSA prints GEM MT. Spelling it out doubled the width of the
     * badge to say the same thing.
     */
    private const GRADE_WORDS = [
        'Black Label' => 'BLK LBL',
        'Gem Mint' => 'GEM MT',
        'Pristine' => 'PRIS',
    ];

    /** "PSA" + "10 Gem Mint" => "PSA 10 GEM MT". */
    private function gradeBadge(string $grader, string $tier): string
    {
        $short = trim($tier);
        foreach (self::GRADE_WORDS as $word => $abbreviation) {
            $short = str_ireplace($word, $abbreviation, $short);
        }

        return trim($grader . ' ' . $short);
    }

    /**
     * Fold the grading and condition chips into one.
     *
     * On a graded card they are not two facts: the grade IS the condition, and
     * the company is what makes the number mean anything - a 9.5 is a different
     * market at Beckett than at CGC. Rendered apart they read as two unrelated
     * badges and cost twice the room. An ungraded card keeps its condition chip
     * exactly as it was; there is nothing to fold.
     *
     * @param array<int, array<string, string>> $chips
     * @return array<int, array<string, string>>
     */
    private function foldGradeChips(array $chips): array
    {
        $gradingAt = null;
        $conditionAt = null;
        foreach ($chips as $at => $chip) {
            if (strpos((string) $chip['cls'], 'cc-chip--grading') !== false) {
                $gradingAt = $at;
            } elseif (strpos((string) $chip['cls'], 'cc-chip--cond') !== false) {
                $conditionAt = $at;
            }
        }
        if ($gradingAt === null || $conditionAt === null) {
            return $chips;
        }

        $chips[$gradingAt]['label'] = $this->gradeBadge(
            (string) $chips[$gradingAt]['label'],
            (string) $chips[$conditionAt]['label']
        );
        unset($chips[$conditionAt]);

        return array_values($chips);
    }

    /** @var array<int, array<int, array<string, string>>> */
    private $tileChipCache = [];

    /**
     * A card tile's chips, from the combination it is offering.
     *
     * The client used to derive these from the variant fragment in the tile's
     * own URL - which it can only do once it is running, so every listing drew
     * bare tiles first and chipped them a moment later, on load and again on
     * every sort and facet. The server knows the combination; it does not need
     * to be told by the browser.
     *
     * Ordered by attribute id, which is the order the URL fragment carries and
     * therefore the order these have always appeared in.
     */
    private function variantTileChips($product): array
    {
        $skuId = (int) $product['id_product_attribute'];
        if (isset($this->tileChipCache[$skuId])) {
            return $this->tileChipCache[$skuId];
        }

        $idLang = (int) $this->context->language->id;
        $conditions = $this->conditionMap();
        $printings = $this->printingMap();
        $gradings = $this->gradingMap();

        $chips = [];
        foreach (Db::getInstance()->executeS(
            'SELECT en.name AS grp, al.name AS value
               FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
               JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
               JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
               JOIN ' . _DB_PREFIX_ . 'attribute_group_lang en
                    ON en.id_attribute_group = a.id_attribute_group AND en.id_lang = 1
              WHERE pac.id_product_attribute = ' . $skuId . '
              ORDER BY a.id_attribute'
        ) ?: [] as $row) {
            $value = (string) $row['value'];
            $slug = $this->anchorSlug($value);

            switch ((string) $row['grp']) {
                case 'Condition':
                    $chips[] = [
                        'label' => $value,
                        'cls' => 'cc-chip--cond ' . ($conditions[$slug]['cls'] ?? ''),
                    ];
                    break;

                case 'Printing':
                    // "Normal" is the absence of a special printing, so it earns
                    // no chip - decided from the English name, because the label
                    // the shopper sees is translated.
                    if ($printings[$slug]['skip'] ?? false) {
                        break;
                    }
                    $chips[] = [
                        'label' => $value,
                        'cls' => 'cc-chip--printing',
                        'edition' => (string) ($printings[$slug]['edition'] ?? ''),
                    ];
                    break;

                case 'Card Language':
                    $chips[] = ['label' => $value, 'cls' => 'cc-chip--language'];
                    break;

                case 'Grading':
                    if ($gradings[$slug]['skip'] ?? false) {
                        break;
                    }
                    $chips[] = ['label' => $value, 'cls' => 'cc-chip--grading'];
                    break;
            }
        }

        /**
         * Shadowed versus shadowless is the largest price difference in the shop
         * and is invisible in the title, so the tile states it. Read from the
         * product's set rather than matched against the title text, which is
         * what the client had to do.
         */
        $run = $this->printRunSets()[$this->setNameOf((int) $product['id_product'])] ?? null;
        if ($run !== null) {
            $translations = $this->translations();
            $chips[] = [
                'label' => $translations[$run === 'shadowless' ? 'shadowless' : 'shadowed'] ?? $run,
                'cls' => 'cc-chip--run cc-chip--' . $run,
                'title' => $translations[$run === 'shadowless' ? 'shadowlessTitle' : 'shadowedTitle'] ?? '',
            ];
        }

        return $this->tileChipCache[$skuId] = $this->foldGradeChips($chips);
    }

    /** @var array<int, string> */
    private $setNameCache = [];

    /** The set a product sits in, which is its default category. */
    private function setNameOf(int $productId): string
    {
        if (isset($this->setNameCache[$productId])) {
            return $this->setNameCache[$productId];
        }

        return $this->setNameCache[$productId] = (string) Db::getInstance()->getValue(
            'SELECT cl.name
               FROM ' . _DB_PREFIX_ . 'product p
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = p.id_category_default
                   AND cl.id_lang = ' . (int) $this->context->language->id . '
              WHERE p.id_product = ' . $productId
        );
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
            'grading' => 'Grading',
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
            'filters' => 'Filters',
            'all' => 'All',
            'browse' => 'Browse',
            'close' => 'Close',
            'viewAll' => 'View all %s',
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
            'confirmCopy' => 'Add this copy to your order',
            'removeCopy' => 'Remove this copy from your order',
            'copyLimitReached' => 'That is every copy in stock',
            'copiesSelected' => '%d copy chosen',
            'copiesSelectedPlural' => '%d copies chosen',
            'loadingCopies' => 'Loading more copies…',
            'showSelectedCards' => 'Show selected cards',
            'cartStockCeiling' => 'Only %d in stock — that is all of them.',
            'confirmSelection' => 'Confirm selection',
            'skipSelection' => 'Skip',
            'confirm' => 'Confirm',
            'cancel' => 'Cancel',
            'selectCard' => 'Select card',
            'unselectCard' => 'Unselect card',
            'skipWarning' => 'Skip choosing? We will ship any available copy for the 1 card you added — you will not know which one until it arrives.',
            'skipWarningPlural' => 'Skip choosing? We will ship any available copies for the %d cards you added — you will not know which ones until they arrive.',
            'removeWhichCard' => 'Which card are you removing?',
            'confirmRemoval' => 'Remove selected',

            'shadowless' => 'Shadowless',
            'shadowed' => 'Not Shadowless',
            'shadowlessTitle' => 'Shadowless print run — no drop shadow on the art box',
            'shadowedTitle' => 'Shadowed print run — not the shadowless variant',
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
            'filters' => 'Filtres',
            'all' => 'Tout',
            'browse' => 'Parcourir',
            'close' => 'Fermer',
            'viewAll' => 'Voir tout : %s',
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
            'confirmCopy' => 'Ajouter cet exemplaire à votre commande',
            'removeCopy' => 'Retirer cet exemplaire de votre commande',
            'copyLimitReached' => 'C\'est tout le stock disponible',
            'copiesSelected' => '%d exemplaire choisi',
            'copiesSelectedPlural' => '%d exemplaires choisis',
            'loadingCopies' => 'Chargement d\'autres exemplaires…',
            'showSelectedCards' => 'Voir les cartes choisies',
            'cartStockCeiling' => 'Seulement %d en stock — c\'est tout.',
            'confirmSelection' => 'Confirmer la sélection',
            'skipSelection' => 'Passer',
            'confirm' => 'Confirmer',
            'cancel' => 'Annuler',
            'selectCard' => 'Choisir cette carte',
            'unselectCard' => 'Retirer cette carte',
            'skipWarning' => 'Passer la sélection ? Nous expédierons n\'importe quel exemplaire disponible pour la carte ajoutée — vous ne saurez pas lequel avant sa réception.',
            'skipWarningPlural' => 'Passer la sélection ? Nous expédierons n\'importe quels exemplaires disponibles pour les %d cartes ajoutées — vous ne saurez pas lesquels avant leur réception.',
            'removeWhichCard' => 'Quelle carte retirez-vous ?',
            'confirmRemoval' => 'Retirer la sélection',

            'shadowless' => 'Sans ombre',
            'shadowed' => 'Pas « shadowless »',
            'shadowlessTitle' => 'Tirage « shadowless » — sans ombre portée sous l’illustration',
            'shadowedTitle' => 'Tirage avec ombre — ce n’est pas la variante « shadowless »',
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
