<?php
/**
 * Remove PrestaShop's demo catalogue (the clothing/poster fixtures the installer
 * seeds) so DoubleSleeve starts from an empty shop.
 *
 * Run ONCE, before you load real inventory:  make purge-demo
 *
 * It deletes every product in the shop. That is correct at bootstrap time - all
 * 19 products are fixtures - but destructive afterwards, which is why the make
 * target asks for confirmation instead of running as part of `make provision`.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

/** Fixture objects, matched by name so we never delete anything provisioned for DoubleSleeve. */
const DEMO_CATEGORIES = ['Clothes', 'Accessories', 'Art'];
const DEMO_ATTRIBUTE_GROUPS = ['Size', 'Color', 'Dimension', 'Paper Type'];
const DEMO_FEATURES = ['Composition', 'Property', 'Styles'];

function line(string $s): void { echo "   $s\n"; }

echo "\n== Purging demo catalogue\n";

// --- products --------------------------------------------------------------
$productIds = array_column(Db::getInstance()->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'product'), 'id_product');
$deleted = 0;
foreach ($productIds as $id) {
    $product = new Product((int) $id);
    if (Validate::isLoadedObject($product) && $product->delete()) {
        ++$deleted;
    }
}
line("products deleted: $deleted");

// --- categories (deletes descendants and their products) -------------------
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$homeId = (int) Configuration::get('PS_HOME_CATEGORY');
$removed = 0;
foreach (DEMO_CATEGORIES as $name) {
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
          WHERE c.id_parent = ' . $homeId . ' AND cl.id_lang = ' . $defaultLang . '
            AND cl.name = "' . pSQL($name) . '"'
    );
    foreach ($rows as $row) {
        // Category::delete() already cascades through every descendant.
        $category = new Category((int) $row['id_category']);
        if (Validate::isLoadedObject($category) && $category->delete()) {
            ++$removed;
            line("category removed: $name");
        }
    }
}
line("demo category trees removed: $removed");

// --- attribute groups ------------------------------------------------------
$removed = 0;
foreach (AttributeGroup::getAttributesGroups($defaultLang) as $group) {
    if (!in_array($group['name'], DEMO_ATTRIBUTE_GROUPS, true)) {
        continue;
    }
    $object = new AttributeGroup((int) $group['id_attribute_group']);
    if (Validate::isLoadedObject($object) && $object->delete()) {
        ++$removed;
        line('attribute group removed: ' . $group['name']);
    }
}
line("demo attribute groups removed: $removed");

// --- features --------------------------------------------------------------
$removed = 0;
foreach (Feature::getFeatures($defaultLang) as $feature) {
    if (!in_array($feature['name'], DEMO_FEATURES, true)) {
        continue;
    }
    $object = new Feature((int) $feature['id_feature']);
    if (Validate::isLoadedObject($object) && $object->delete()) {
        ++$removed;
        line('feature removed: ' . $feature['name']);
    }
}
line("demo features removed: $removed");

Category::regenerateEntireNtree();
Search::indexation(true);

echo "\nDemo catalogue purged. Shop is empty and ready for real inventory.\n";
