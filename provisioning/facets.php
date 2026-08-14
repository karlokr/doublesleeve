<?php
/**
 * Per-section faceted search templates.
 *
 * PrestaShop features are a single global list, so without scoped templates every
 * category offers every filter - Singles pages would offer "Sleeve Size" and
 * Accessories would offer "Regulation Mark". Filter templates are what actually
 * deliver a tidy, section-appropriate filter rail, and they are the step most
 * shops skip.
 *
 * Idempotent: drops and rebuilds the DoubleSleeve templates each run.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

use PrestaShop\Module\FacetedSearch\Filters\Converter;

const CHECKBOX = Converter::WIDGET_TYPE_CHECKBOX;

/**
 * Every facet is CHECKBOX. Dropdowns were used for the long lists (Pokemon has 155
 * values in stock, Artist 74) but a dropdown is single-select, and there is no
 * reason a shopper cannot want Charizard OR Pikachu, or two artists.
 *
 * filter_show_limit stays 0 - i.e. render everything. It truncates SERVER-SIDE and
 * Hummingbird renders no "show more" control, so a limit of 8 silently made 147 of
 * the 155 Pokemon unreachable. The length is handled in the theme instead: the
 * list scrolls, and theme.js adds a type-to-filter box to any facet over 10 rows.
 */
const SLIDER = Converter::WIDGET_TYPE_SLIDER;

function line(string $s): void { echo "   + $s\n"; }
function note(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Faceted search templates\033[0m\n";

$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$shopId = (int) Context::getContext()->shop->id;
$db = Db::getInstance();

// ---------------------------------------------------------------------------
// lookups
// ---------------------------------------------------------------------------
$featureIds = [];
foreach (Feature::getFeatures($defaultLang) as $feature) {
    $featureIds[$feature['name']] = (int) $feature['id_feature'];
}

$groupIds = [];
foreach (AttributeGroup::getAttributesGroups($defaultLang) as $group) {
    $groupIds[$group['name']] = (int) $group['id_attribute_group'];
}

function categoryIdByPath(array $names): ?int
{
    $parentId = (int) Configuration::get('PS_HOME_CATEGORY');
    foreach ($names as $name) {
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
              WHERE c.id_parent = ' . $parentId . ' AND cl.name = "' . pSQL($name) . '" LIMIT 1'
        );
        if (!$rows) {
            return null;
        }
        $parentId = (int) $rows[0]['id_category'];
    }

    return $parentId;
}

/** A category plus every descendant - a template must cover the set pages too. */
function withDescendants(?int $categoryId): array
{
    if ($categoryId === null) {
        return [];
    }
    $category = new Category($categoryId);
    $ids = [$categoryId];
    foreach ($category->getAllChildren($GLOBALS['defaultLang']) as $child) {
        $ids[] = (int) $child->id;
    }

    return $ids;
}

// ---------------------------------------------------------------------------
// make features and attribute groups filterable
// ---------------------------------------------------------------------------
// Nothing can be filtered on until it is flagged indexable; these tables ship
// empty, which is why a fresh shop shows almost no filters.
foreach ($featureIds as $id) {
    $db->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature (id_feature, indexable)
                  VALUES (' . (int) $id . ', 1) ON DUPLICATE KEY UPDATE indexable = 1');
}
line(count($featureIds) . ' features marked indexable');

foreach ($groupIds as $id) {
    $db->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_group (id_attribute_group, indexable)
                  VALUES (' . (int) $id . ', 1) ON DUPLICATE KEY UPDATE indexable = 1');
}
line(count($groupIds) . ' attribute groups marked indexable');

// ---------------------------------------------------------------------------
// template definitions
// ---------------------------------------------------------------------------
/**
 * Ordering matters - it is the order filters appear in the rail. Condition and
 * price come first for singles because that is what buyers narrow on first.
 *
 * Pokémon and Artist use dropdowns: 1,025 checkboxes would be unusable.
 */
$templates = [
    'DoubleSleeve - Singles' => [
        'path' => ['Pokémon', 'Singles'],
        'filters' => [
            ['price', SLIDER],
            ['stock', CHECKBOX],
            ['subcategories', CHECKBOX],
            /**
             * Region and language sit directly under the category chips, above
             * even condition.
             *
             * They are the widest cuts on the rail - region decides which
             * catalogue you are shopping and language which printing of it - so
             * burying them under nine card-detail filters made the two facets that
             * halve the result set the hardest ones to reach. Everything below
             * them narrows within a choice these two have already made.
             */
            ['feat:Region', CHECKBOX],
            ['ag:Card Language', CHECKBOX],
            /**
             * Grading beside Condition, because together they ARE the copy: which
             * scale (Raw or a slab company) and where on it. This filter is also
             * what the nav's Graded entry deep-links into - PSA in the menu is
             * Singles with Grading=PSA, not a category.
             */
            ['ag:Grading', CHECKBOX],
            ['ag:Condition', CHECKBOX],
            ['feat:Set', CHECKBOX],
            ['feat:Pokemon', CHECKBOX],
            ['feat:Rarity', CHECKBOX],
            ['ag:Printing', CHECKBOX],
            ['feat:Pokemon Type', CHECKBOX],
            ['feat:Card Type', CHECKBOX],
            ['feat:Stage', CHECKBOX],
            ['feat:Regulation Mark', CHECKBOX],
            ['feat:Format Legality', CHECKBOX],
            ['feat:Print Run', CHECKBOX],
            ['feat:Release Year', CHECKBOX],
            ['feat:Artist', CHECKBOX],
        ],
    ],
    /**
     * No Graded template any more. Graded copies are combinations on the cards'
     * own listings (Grading attribute group), so graded shopping happens in the
     * Singles template via ag:Grading above - the nav's Graded entry deep-links
     * there. The old template filtered a category tree that no longer holds
     * products, over features (Grading Company, Grade, the four subgrades) the
     * grader-axis migration retired.
     */
    'DoubleSleeve - Sealed' => [
        'path' => ['Pokémon', 'Sealed'],
        'filters' => [
            ['price', SLIDER],
            ['stock', CHECKBOX],
            /**
             * No "Sealed Product Type" facet beside this one.
             *
             * The subcategories ARE the product types - Booster Boxes, Elite
             * Trainer Boxes, Booster Packs - so the feature restated the same
             * seven values with the same counts, one rail apart, and its values
             * were never translated. Two filters for one axis is worse than none.
             */
            ['subcategories', CHECKBOX],
            ['feat:Set', CHECKBOX],
            /**
             * Print region is a FACET here and a category under Singles, and that
             * asymmetry is deliberate - see lib/region.php.
             *
             * It is also what makes "all booster boxes, either region" reachable at
             * all. In a tree the both-view is the parent, so a Sealed > Western >
             * Booster Boxes layout would have no combined node above it; leaving
             * the product types as the tree and region as the rail gives western,
             * japanese AND both from the same page.
             *
             * Not to be confused with "Print Region" below, which is the print-run
             * axis (Shadowless, 1st Edition) and an entirely different question.
             */
            ['feat:Region', CHECKBOX],
            ['feat:Print Region', CHECKBOX],
            ['feat:Seal Status', CHECKBOX],
            ['feat:Promo Included', CHECKBOX],
            ['feat:Release Year', CHECKBOX],
            ['feat:Pack Count', CHECKBOX],
        ],
    ],
];

// ---------------------------------------------------------------------------
// rebuild
// ---------------------------------------------------------------------------
/**
 * Both names: the shop was CryptoCards before it was DoubleSleeve, and a template
 * left behind under the old name is a live facet configuration nobody maintains -
 * "CryptoCards - Supplies" outlived the entire product line it filtered.
 */
$existing = $db->executeS(
    'SELECT id_layered_filter FROM ' . _DB_PREFIX_ . 'layered_filter
      WHERE name LIKE "DoubleSleeve - %" OR name LIKE "CryptoCards - %"'
);
foreach ($existing as $row) {
    $db->delete('layered_filter_shop', 'id_layered_filter = ' . (int) $row['id_layered_filter']);
    $db->delete('layered_filter', 'id_layered_filter = ' . (int) $row['id_layered_filter']);
}
// The stock "My template" points at deleted demo categories; it only gets in the way.
$db->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_filter WHERE name LIKE "My template%"');

foreach ($templates as $name => $spec) {
    $categoryIds = withDescendants(categoryIdByPath($spec['path']));
    foreach ($spec['extra_paths'] ?? [] as $extraPath) {
        $categoryIds = array_merge($categoryIds, withDescendants(categoryIdByPath($extraPath)));
    }
    $categoryIds = array_values(array_unique(array_filter($categoryIds)));

    if (!$categoryIds) {
        note("$name: no categories resolved - skipped");
        continue;
    }

    $filters = [
        'categories' => $categoryIds,
        'controllers' => ['category'],
        'shop_list' => [$shopId => $shopId],
    ];

    $skipped = [];
    foreach ($spec['filters'] as [$key, $widget]) {
        if (str_starts_with($key, 'feat:')) {
            $featureName = substr($key, 5);
            if (!isset($featureIds[$featureName])) {
                $skipped[] = $featureName;
                continue;
            }
            $slot = 'layered_selection_feat_' . $featureIds[$featureName];
        } elseif (str_starts_with($key, 'ag:')) {
            $groupName = substr($key, 3);
            if (!isset($groupIds[$groupName])) {
                $skipped[] = $groupName;
                continue;
            }
            $slot = 'layered_selection_ag_' . $groupIds[$groupName];
        } elseif ($key === 'price') {
            $slot = 'layered_selection_price_slider';
        } elseif ($key === 'stock') {
            $slot = 'layered_selection_stock';
        } elseif ($key === 'subcategories') {
            $slot = 'layered_selection_subcategories';
        } else {
            continue;
        }

        $filters[$slot] = ['filter_type' => $widget, 'filter_show_limit' => 0];
    }

    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'layered_filter (name, filters, n_categories, date_add)
         VALUES ("' . pSQL($name) . '", "' . pSQL(serialize($filters), true) . '", '
         . count($categoryIds) . ', NOW())'
    );
    $filterId = (int) $db->Insert_ID();
    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'layered_filter_shop (id_layered_filter, id_shop)
         VALUES (' . $filterId . ', ' . $shopId . ')'
    );

    $filterCount = count($filters) - 3; // minus categories/controllers/shop_list
    line(sprintf('%s: %d filters over %d categories', $name, $filterCount, count($categoryIds)));
    if ($skipped) {
        note('  missing features skipped: ' . implode(', ', $skipped));
    }
}

// ---------------------------------------------------------------------------
// rebuild derived tables
// ---------------------------------------------------------------------------
$module = Module::getInstanceByName('ps_facetedsearch');
if (!$module) {
    note('ps_facetedsearch not installed - filters will not render');

    return;
}

$module->buildLayeredCategories();
line('layered_category rebuilt from templates');

$module->indexAttributes();
$module->fullPricesIndexProcess();
line('attribute and price indexes rebuilt');
