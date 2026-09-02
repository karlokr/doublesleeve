<?php
/**
 * Rebuilds the set taxonomy from TCGplayer's groups.
 *
 * TCGplayer's Pokemon organisation is category -> group, flat: 217 groups with no
 * series or era field. A flat 217-item list is not navigable, so groups are filed
 * under the era they belong to (see lib/era.php for how era is derived, and why
 * release year alone was the wrong axis - nobody shops for "a 2016 card", they
 * shop for XY).
 *
 * Set names, abbreviations and group ids are TCGplayer's verbatim - the source map
 * stays a 1:1 lookup instead of a translation layer needing maintenance every set.
 *
 *   make sets-align
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/era.php';
require_once __DIR__ . '/../lib/era-jp.php';
require_once __DIR__ . '/../lib/region.php';

/**
 * Which print region this run builds.
 *
 * Eras hang off Singles / <Region>, not off Singles, because the era lists differ
 * per region - see lib/region.php. Everything below is scoped to that node, most
 * importantly the sweep that retires stale categories: scoped to Singles it would
 * treat a sibling region's whole branch as legacy and delete it.
 *
 *   make sets-align                 (Western, TCGplayer category 3)
 *   ... sets-tcgplayer.php Japanese (TCGplayer category 85)
 */
define('REGION', (string) (($argv[1] ?? '') !== '' && !str_starts_with((string) $argv[1], '--') ? $argv[1] : 'Western'));
/**
 * TAXONOMY ONLY by default. This script's job is the set tree: eras, set
 * categories, the group->category map, the legacy sweep and the ordering.
 *
 * --rehome additionally re-homes every mapped product onto its set and re-derives
 * the Set and Region features across the whole region. That is a REPAIR pass -
 * bulk surgery for when associations have actually drifted - not part of routine
 * operation. It used to run unconditionally, which meant adding one card to the
 * shop moved 277 products around to do it; a selling platform's unit of work is
 * one card, and one card's associations are written by inventory/add-card.php
 * when the card enters stock.
 */
define('REHOME', in_array('--rehome', $argv ?? [], true));
if (!in_array(REGION, REGION_ORDER, true)) {
    fwrite(STDERR, "unknown region: " . REGION . "\n");
    exit(1);
}

/**
 * One groups CSV per region, because they are different catalogues: 217 Western
 * groups from TCGplayer category 3, 454 Japanese ones from category 85.
 */
const GROUPS_CSV_BY_REGION = [
    'Western' => '/provisioning/data/tcgplayer-groups.csv',
    'Japanese' => '/provisioning/data/tcgplayer-groups-jp.csv',
];
define('GROUPS_CSV', GROUPS_CSV_BY_REGION[REGION] ?? '');
const SERIES_CSV = '/provisioning/data/pokemon-sets.csv';
/** Official French set names - see fetch-set-names.php. */
const NAMES_FR_CSV = '/provisioning/data/set-names-fr.csv';

/**
 * Parenthetical qualifiers that must survive translation.
 *
 * Bulbapedia gives the French name of the SET, so "Base Set (Shadowless)" comes
 * back as plain "Set de Base" - identical to its shadowed sibling. Dropping the
 * qualifier would make the one distinction on the site worth four figures
 * invisible on the French storefront.
 */
const QUALIFIER_FR = ['Shadowless' => 'Sans ombre'];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Set taxonomy from TCGplayer groups\033[0m\n";

$db = Db::getInstance();
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

$db->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'tcg_group_category (
    group_id     INT UNSIGNED NOT NULL,
    id_category  INT UNSIGNED NOT NULL,
    abbreviation VARCHAR(32) NOT NULL DEFAULT "",
    published_on DATE DEFAULT NULL,
    PRIMARY KEY (group_id),
    set_code     VARCHAR(16) NOT NULL DEFAULT "",
    KEY idx_category (id_category)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');

function everyLang(string $value): array
{
    $out = [];
    foreach (Language::getLanguages(false) as $language) {
        $out[(int) $language['id_lang']] = $value;
    }

    return $out;
}

/** Per-locale strings, falling back to 'en' for anything unmapped. */
function perLang(array $byLocale): array
{
    $out = [];
    foreach (Language::getLanguages(false) as $language) {
        $locale = (string) ($language['locale'] ?? '');
        $out[(int) $language['id_lang']] =
            $byLocale[$locale] ?? $byLocale[substr($locale, 0, 2)] ?? $byLocale['en'];
    }

    return $out;
}

function findChild(int $parentId, string $name): ?int
{
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
          WHERE c.id_parent = ' . $parentId . ' AND cl.name = "' . pSQL($name) . '"'
    );

    return $rows ? (int) $rows[0]['id_category'] : null;
}

/**
 * The set's name per language: official French where one exists, English
 * otherwise. Sets with no French entry are usually English-only releases
 * (promo runs, trainer kits), so keeping the English name is correct.
 */
function setLabel(string $englishName, array $frenchByName): array
{
    $french = trim((string) ($frenchByName[$englishName] ?? ''));
    if ($french === '') {
        return everyLang($englishName);
    }

    // Carry a trailing "(Qualifier)" across - the French source drops it.
    if (preg_match('/\(([^)]+)\)\s*$/', $englishName, $m)) {
        $qualifier = trim($m[1]);
        if (!str_contains($french, $qualifier) && !str_contains($french, QUALIFIER_FR[$qualifier] ?? '~')) {
            $french .= ' (' . (QUALIFIER_FR[$qualifier] ?? $qualifier) . ')';
        }
    }

    return perLang(['en' => $englishName, 'fr' => $french]);
}

function ensureCategory(int $parentId, string $name, ?array $label = null): int
{
    $existing = findChild($parentId, $name);
    if ($existing !== null) {
        return $existing;
    }
    $category = new Category();
    $category->id_parent = $parentId;
    $category->name = $label ?? everyLang($name);
    $category->active = true;
    $rewrite = [];
    foreach ($category->name as $idLang => $value) {
        $slug = Tools::str2url($value);
        $rewrite[$idLang] = ($slug === null || $slug === '' || $slug === '-')
            ? 'set-' . md5((string) $value) : $slug;
    }
    $category->link_rewrite = $rewrite;
    $category->add();

    return (int) $category->id;
}

// ---------------------------------------------------------------------------
$singlesId = (int) $db->getValue(
    'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $defaultLang . '
      WHERE cl.name = "Singles"'
);
if (!$singlesId) {
    warn('Singles category not found');
    exit(1);
}

// The region node the whole run is anchored to. Created here rather than in
// setup.php so a region comes into existence with the sets that justify it.
$regionId = ensureCategory($singlesId, REGION, perLang(regionCategoryLabel(REGION)));
$pokemonId = (int) $db->getValue(
    'SELECT id_parent FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . $singlesId
);
line('region: ' . REGION . " (category $regionId)");

if (!is_readable(GROUPS_CSV)) {
    warn('groups CSV missing: ' . GROUPS_CSV);
    exit(1);
}

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$groups = [];
while (($row = fgetcsv($handle)) !== false) {
    $groups[] = array_combine($header, array_pad($row, count($header), ''));
}
fclose($handle);

// Newest first: collectors shop current sets far more than 2003 ones.
usort($groups, static fn ($a, $b) => strcmp((string) $b['published_on'], (string) $a['published_on']));

/** English set name => official French name. */
$frenchByName = [];
if (is_readable(NAMES_FR_CSV)) {
    $fh = fopen(NAMES_FR_CSV, 'r');
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < 3 || trim((string) $r[2]) === '') {
            continue;
        }
        // The CSV is keyed on the full TCGplayer name ("SV08: Surging Sparks"),
        // but categories are named with the release code stripped, so index both.
        $frenchByName[(string) $r[1]] = (string) $r[2];
        $frenchByName[setDisplayName((string) $r[1])] = (string) $r[2];
    }
    fclose($fh);
    line(count($frenchByName) . ' French set names loaded');
} else {
    warn('French set names CSV missing - sets will be English in every locale');
}

$seriesByName = loadSeriesMap(SERIES_CSV);
if ($seriesByName === []) {
    warn('series CSV missing - eras will fall back to release date only');
}

// Create era parents up-front, in ERA_ORDER, so the storefront lists them newest
// era first rather than in whatever order groups happen to be processed.
$eraIds = [];
$keep = [];
$created = 0;
$eraCounts = [];

foreach ($groups as $group) {
    $name = trim((string) $group['name']);
    if ($name === '') {
        continue;
    }
    /**
     * Japanese sets resolve against their own block list, not ERA_ORDER.
     *
     * The Japanese resolver leads on the set's ABBREVIATION rather than its name:
     * TCGplayer's Japanese names are English descriptions of Japanese products
     * ("S12a: VSTAR Universe"), so the code is the only field that reliably says
     * which block a set is in. pokemontcg.io carries no Japanese series data at
     * all, which is why $seriesByName has no part in it.
     */
    $era = REGION === 'Japanese'
        ? resolveEraJp($name, (string) ($group['abbreviation'] ?? ''), (string) $group['published_on'])
        : resolveEra($name, (string) $group['published_on'], $seriesByName);
    $eraCounts[$era] = ($eraCounts[$era] ?? 0) + 1;

    if (!isset($eraIds[$era])) {
        $eraLabel = REGION === 'Japanese'
            ? perLang(['en' => $era, 'fr' => eraJpFrench($era)])
            : perLang(['en' => $era, 'fr' => eraFrench($era)]);
        $eraIds[$era] = ensureCategory($regionId, $era, $eraLabel);
        $keep[$eraIds[$era]] = true;

        /**
         * Refresh the label on an era that already exists.
         *
         * Eras were created with everyLang(), so the French storefront grouped its
         * sets under "Scarlet & Violet" and "Promos & Specials" - the era headings
         * were the largest untranslated text on the page.
         */
        $eraCategory = new Category($eraIds[$era]);
        if (Validate::isLoadedObject($eraCategory)) {
            $eraCategory->name = $eraLabel;
            $rewrite = [];
            foreach ($eraLabel as $idLang => $value) {
                $slug = Tools::str2url($value);
                $rewrite[$idLang] = ($slug === null || $slug === '' || $slug === '-')
                    ? 'era-' . md5((string) $value) : $slug;
            }
            $eraCategory->link_rewrite = $rewrite;
            $eraCategory->update();
        }
    }

    // Categories carry the display name; the release code lives in the mapping
    // table so it is still queryable without being shouted on every tile.
    $label = setDisplayName($name);
    $existing = findChild($eraIds[$era], $label);
    $categoryId = $existing ?? ensureCategory(
        $eraIds[$era],
        $label,
        setLabel($label, $frenchByName)
    );

    // Refresh labels on sets that already exist - they were created before the
    // French names were available.
    if ($existing !== null) {
        $category = new Category($existing);
        if (Validate::isLoadedObject($category)) {
            $category->name = setLabel($label, $frenchByName);
            $category->update();
        }
    }
    if ($existing === null) {
        ++$created;
    }
    $keep[$categoryId] = true;

    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'tcg_group_category
            (group_id, id_category, abbreviation, published_on, set_code)
         VALUES (' . (int) $group['group_id'] . ', ' . $categoryId . ',
                 "' . pSQL((string) $group['abbreviation']) . '",
                 ' . ($group['published_on'] !== '' ? '"' . pSQL((string) $group['published_on']) . '"' : 'NULL') . ',
                 "' . pSQL(setCode($name)) . '")
         ON DUPLICATE KEY UPDATE id_category = VALUES(id_category),
                                 abbreviation = VALUES(abbreviation),
                                 published_on = VALUES(published_on),
                                 set_code = VALUES(set_code)'
    );
}
line(count($groups) . ' TCGplayer groups mapped (' . $created . ' new categories, '
    . count($eraIds) . ' eras)');
// Region's own block order - ERA_ORDER is the Western list and shares only some
// of its names with the Japanese one.
define('ERA_ORDER_REGION', REGION === 'Japanese' ? ERA_JP_ORDER : ERA_ORDER);
foreach (ERA_ORDER_REGION as $era) {
    if (isset($eraCounts[$era])) {
        line(sprintf('   %-24s %3d sets', $era, $eraCounts[$era]));
    }
}


// ---------------------------------------------------------------------------
// re-home products onto their TCGplayer set (--rehome repair pass only)
// ---------------------------------------------------------------------------
if (!REHOME) {
    line('products untouched (taxonomy only - pass --rehome for the repair pass)');
}
$moved = 0;
if (REHOME) {
/**
 * Only products whose set sits under THIS region's node.
 *
 * tcg_group_category spans every region, so an unscoped read hands a Japanese run
 * the entire Western catalogue - which it would then re-home, re-associate and
 * stamp with the wrong region. Scoped through the set's era to the region node,
 * each run only ever touches the sets it just built.
 */
$products = $db->executeS(
    'SELECT p.id_product, m.tcgplayer_group_id, g.id_category
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
       JOIN ' . _DB_PREFIX_ . 'tcg_group_category g ON g.group_id = m.tcgplayer_group_id
       JOIN ' . _DB_PREFIX_ . 'category setcat ON setcat.id_category = g.id_category
       JOIN ' . _DB_PREFIX_ . 'category era ON era.id_category = setcat.id_parent
      WHERE m.kind = "single" AND era.id_parent = ' . (int) $regionId
) ?: [];

foreach ($products as $row) {
    $product = new Product((int) $row['id_product']);
    if (!Validate::isLoadedObject($product)) {
        continue;
    }
    $target = (int) $row['id_category'];
    $product->id_category_default = $target;
    $product->update();
    /**
     * Associate the whole ancestor chain, not just the set.
     *
     * PrestaShop category pages list only DIRECTLY associated products - a parent
     * shows nothing just because its children hold stock. Associating the set and
     * Singles alone is why "Pokémon" and every era page rendered empty: the
     * both-regions and whole-catalogue views the menu points at had no products in
     * them at all.
     */
    $eraId = (int) $db->getValue(
        'SELECT id_parent FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . $target
    );
    $product->deleteCategories();
    $product->addToCategories(array_values(array_unique(array_filter(
        [$target, $eraId, $regionId, $singlesId, $pokemonId]
    ))));
    ++$moved;
}
    line("$moved singles re-homed onto TCGplayer sets");
}

// ---------------------------------------------------------------------------
// retire the old pokemontcg.io series/set tree
// ---------------------------------------------------------------------------
// Sweep BOTH levels under Singles - era parents and the set categories beneath
// them. Sweeping only direct children left 68 orphans behind when set names were
// cleaned of their release codes ("SV04: Paradox Rift" -> "Paradox Rift"): the
// renamed set was created fresh and the old one simply stayed, giving the era two
// entries for the same set. Same failure as the duplicate year/era trees.
// Scoped to THIS region's node. Scoped to Singles it would sweep sibling regions
// too: $keep only ever holds the eras and sets this run built, so a Western run
// would find the entire Japanese branch "legacy" and delete it.
$keepIds = implode(',', array_map('intval', array_keys($keep)));
$legacy = $db->executeS(
    'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
       LEFT JOIN ' . _DB_PREFIX_ . 'category par ON par.id_category = c.id_parent
      WHERE (c.id_parent = ' . $regionId . ' OR par.id_parent = ' . $regionId . ')
        AND c.id_category NOT IN (' . $keepIds . ')'
) ?: [];

$removed = 0;
$held = [];
foreach ($legacy as $row) {
    $categoryId = (int) $row['id_category'];
    // A stale category holding products means the re-home step missed something;
    // deleting it would hide the problem, so report and leave it alone.
    $products = (int) $db->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'category_product WHERE id_category = ' . $categoryId
    );
    if ($products > 0) {
        $held[] = $categoryId . " ($products products)";
        continue;
    }
    $category = new Category($categoryId);
    if (Validate::isLoadedObject($category) && $category->delete()) {
        ++$removed;
    }
}
line("$removed legacy series/set categories removed");
if ($held !== []) {
    warn('stale categories still holding products: ' . implode(', ', $held));
}

// Order era categories newest-era-first. This has to happen after the legacy
// sweep above: Category::delete() calls cleanPositions(), which renumbers every
// surviving sibling sequentially and silently undid this ordering when it ran
// earlier in the script.
foreach (array_values(ERA_ORDER_REGION) as $position => $era) {
    if (!isset($eraIds[$era])) {
        continue;
    }
    // PrestaShop keeps position in BOTH tables and the front office reads
    // category_shop - updating only `category` reorders the back office while the
    // storefront menu keeps its old order.
    $db->execute('UPDATE ' . _DB_PREFIX_ . 'category SET position = ' . (int) $position
        . ' WHERE id_category = ' . (int) $eraIds[$era]);
    $db->execute('UPDATE ' . _DB_PREFIX_ . 'category_shop SET position = ' . (int) $position
        . ' WHERE id_category = ' . (int) $eraIds[$era]);
}

Category::regenerateEntireNtree();
(new Category((int) Configuration::get('PS_HOME_CATEGORY')))
    ->recalculateLevelDepth((int) Configuration::get('PS_HOME_CATEGORY'));
line('category tree regenerated');

// ---------------------------------------------------------------------------
// state the set explicitly on every product (--rehome repair pass only)
// ---------------------------------------------------------------------------
if (REHOME) {
/**
 * The set is otherwise only visible in the breadcrumb, which is not good enough
 * for print runs that share a card name AND number. TCGplayer keeps two parallel
 * Base Set groups - "Base Set" (shadowed) and "Base Set (Shadowless)" - both
 * containing Charizard 004/102 at very different values. A buyer must be able to
 * see which one they are looking at without reading the URL.
 */
$featureId = null;
foreach (Feature::getFeatures($defaultLang) as $feature) {
    if ($feature['name'] === 'Set') {
        $featureId = (int) $feature['id_feature'];
        break;
    }
}
// "Extension" is the standard French Pokemon TCG term for a set.
$setLabel = perLang(['en' => 'Set', 'fr' => 'Extension']);

if ($featureId === null) {
    $feature = new Feature();
    $feature->name = $setLabel;
    $feature->position = 0;
    $feature->add();
    $featureId = (int) $feature->id;
    line('feature created: Set');
} else {
    // Refresh labels on an existing feature: this was created with everyLang(),
    // so the French storefront showed the English word until now.
    $feature = new Feature($featureId);
    if (Validate::isLoadedObject($feature)) {
        $feature->name = $setLabel;
        $feature->update();
    }
}

/**
 * The VALUES are the set names, and those are localised on the category already -
 * so they are read per language from there rather than written once in English.
 *
 * They were created with everyLang(), which is why the French "Extension" facet
 * listed "Base Set" and "Surging Sparks" next to fully French chrome: the category
 * had been translated and this copy of the same name had not.
 */
$setNamesByCategory = [];
foreach ($db->executeS(
    'SELECT id_category, id_lang, name FROM ' . _DB_PREFIX_ . 'category_lang'
) ?: [] as $row) {
    $setNamesByCategory[(int) $row['id_category']][(int) $row['id_lang']] = (string) $row['name'];
}

$valueCache = [];
$tagged = 0;
$localised = 0;
// Scoped to this region's sets, like the re-home above and for the same reason:
// both the Set feature and the Region feature below are written from these rows,
// and unscoped they described the Western catalogue as Japanese.
$rows = $db->executeS(
    'SELECT p.id_product, g.id_category
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
       JOIN ' . _DB_PREFIX_ . 'tcg_group_category g ON g.group_id = m.tcgplayer_group_id
       JOIN ' . _DB_PREFIX_ . 'category setcat ON setcat.id_category = g.id_category
       JOIN ' . _DB_PREFIX_ . 'category era ON era.id_category = setcat.id_parent
      WHERE era.id_parent = ' . (int) $regionId
) ?: [];

foreach ($rows as $row) {
    $categoryId = (int) $row['id_category'];
    $names = $setNamesByCategory[$categoryId] ?? [];
    $setName = $names[$defaultLang] ?? '';
    if ($setName === '') {
        continue;
    }

    if (!isset($valueCache[$categoryId])) {
        $existing = (int) $db->getValue(
            'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fvl.id_feature_value = fv.id_feature_value
              WHERE fv.id_feature = ' . $featureId . ' AND fvl.id_lang = ' . $defaultLang . '
                AND fvl.value = "' . pSQL($setName) . '"'
        );
        $featureValue = $existing ? new FeatureValue($existing) : new FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->custom = false;
        $featureValue->value = $names;
        if ($existing && Validate::isLoadedObject($featureValue)) {
            $featureValue->update();
            ++$localised;
        } elseif (!$existing) {
            $featureValue->add();
        }
        $valueCache[$categoryId] = (int) $featureValue->id;
    }

    $productId = (int) $row['id_product'];
    $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'feature_product
                   WHERE id_product = ' . $productId . ' AND id_feature = ' . $featureId);
    (new Product($productId))->addFeaturesToDB($featureId, $valueCache[$categoryId]);
    ++$tagged;
}
line("$tagged products tagged with their TCGplayer set ($localised set names re-localised)");

/**
 * Region, derived from the set.
 *
 * Every set this run builds comes from one TCGplayer category - 3 is the Western
 * catalogue, 85 the Japanese one - so every card filed under it carries that run's
 * region. Tagged from REGION rather than hardcoded: a Japanese run was stamping
 * "Western" onto whatever it touched, which was harmless only for as long as there
 * was no Japanese stock to get it wrong about.
 *
 * Derived, never entered: region is a fact about the set, so a card cannot disagree
 * with the set it is filed under.
 */
$regionFeature = null;
foreach (Feature::getFeatures($defaultLang) as $feature) {
    if ($feature['name'] === 'Region' || $feature['name'] === 'Région') {
        $regionFeature = (int) $feature['id_feature'];
        break;
    }
}

if ($regionFeature !== null) {
    $regionValue = (int) $db->getValue(
        'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = 1
          WHERE fv.id_feature = ' . $regionFeature . ' AND fvl.value = "' . pSQL(REGION) . '"'
    );

    $regioned = 0;
    if ($regionValue) {
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'feature_product
                           WHERE id_product = ' . $productId . ' AND id_feature = ' . $regionFeature);
            $db->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'feature_product (id_feature, id_product, id_feature_value)
                 VALUES (' . $regionFeature . ', ' . $productId . ', ' . $regionValue . ')'
            );
            ++$regioned;
        }
    }
    line("$regioned products tagged " . REGION);
} else {
    warn('Region feature missing - run setup.php first');
}

/**
 * Drop Set values nothing points at any more.
 *
 * Renaming a set mints a new value and orphans the old one, so the facet still
 * offered "SV08: Surging Sparks" beside "Surging Sparks" long after the release
 * code was dropped from display names - two entries, same set, one of them dead.
 */
$orphans = $db->executeS(
    'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
      WHERE fv.id_feature = ' . $featureId . '
        AND NOT EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'feature_product fp
                         WHERE fp.id_feature_value = fv.id_feature_value)'
) ?: [];
foreach ($orphans as $orphan) {
    (new FeatureValue((int) $orphan['id_feature_value']))->delete();
}
line(count($orphans) . ' orphaned set values removed');
}

Tools::clearAllCache();
