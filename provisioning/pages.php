<?php
/**
 * Generates the set directory page and wires it into the main menu.
 *
 * This is the replacement for drilling era -> set in a dropdown. Set browsing is a
 * completionist path, not the primary way anyone shops singles, so it gets a proper
 * destination page instead of six levels of hover menu.
 *
 * The page is deliberately static HTML - PS_USE_HTMLPURIFIER strips <script> from
 * CMS content, and per-page JS search would be redundant once the global search
 * indexes sets anyway. For the same reason the markup uses only div/p/h2/a/img/span:
 * HTMLPurifier defaults to an XHTML doctype and silently drops HTML5 sectioning
 * elements like <section> and <nav>, along with attributes such as loading="lazy".
 * All styling is by class, defined in the cryptocards_theme stylesheet.
 *
 * Idempotent: rewrites the page content each run.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const PAGE_REWRITE = 'pokemon-sets';

function line(string $s): void { echo "   + $s\n"; }

echo "\n\033[1m== Set directory\033[0m\n";

$context = Context::getContext();
$link = $context->link;
$languages = Language::getLanguages(false);
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

// ---------------------------------------------------------------------------
// collect the set tree: Pokémon > Singles > <era> > <set>
// ---------------------------------------------------------------------------
function childCategories(int $parentId, int $idLang): array
{
    return Db::getInstance()->executeS(
        'SELECT c.id_category, cl.name, cl.link_rewrite
           FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl
             ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . '
          WHERE c.id_parent = ' . (int) $parentId . ' AND c.active = 1
          ORDER BY c.position'
    ) ?: [];
}

/**
 * Per-set display data: how many singles we actually hold, the TCGplayer
 * abbreviation, and whether a logo was downloaded for the category.
 *
 * The abbreviation matters because only 114 of 217 sets have a logo - the rest
 * render a typographic tile instead, so every card is the same size whether art
 * exists or not.
 */
function setExtras(): array
{
    $out = [];
    foreach (Db::getInstance()->executeS(
        'SELECT id_category, abbreviation FROM ' . _DB_PREFIX_ . 'tcg_group_category'
    ) ?: [] as $row) {
        $out[(int) $row['id_category']]['abbr'] = trim((string) $row['abbreviation']);
    }
    foreach (Db::getInstance()->executeS(
        'SELECT cp.id_category, COUNT(DISTINCT cp.id_product) n
           FROM ' . _DB_PREFIX_ . 'category_product cp
          GROUP BY cp.id_category'
    ) ?: [] as $row) {
        $out[(int) $row['id_category']]['stock'] = (int) $row['n'];
    }

    return $out;
}

/** Initials to fall back on when a set has neither logo nor abbreviation. */
function setInitials(string $name): string
{
    $stripped = preg_replace('/^[A-Za-z0-9&]+[0-9]*\s*[:-]\s*/', '', $name) ?? $name;
    preg_match_all('/\b([A-Za-z0-9])/', $stripped, $m);

    return strtoupper(implode('', array_slice($m[1], 0, 3))) ?: '?';
}

function findByName(int $parentId, string $name, int $idLang): ?int
{
    foreach (childCategories($parentId, $idLang) as $row) {
        if ($row['name'] === $name) {
            return (int) $row['id_category'];
        }
    }

    return null;
}

$homeId = (int) Configuration::get('PS_HOME_CATEGORY');
$pokemonId = findByName($homeId, 'Pokémon', $defaultLang);
$singlesId = $pokemonId ? findByName($pokemonId, 'Singles', $defaultLang) : null;

if ($singlesId === null) {
    echo "   ! Pokémon > Singles not found - cannot build set directory\n";

    return;
}

// ---------------------------------------------------------------------------
// build the page body, per language
// ---------------------------------------------------------------------------
$copy = [
    'en' => [
        'title' => 'Browse Pokémon Sets',
        'meta' => 'Every Pokémon TCG set from Base Set to the current series, grouped by era.',
        'intro' => 'Every English Pokémon TCG expansion, grouped by era. Looking for one card? '
            . 'Search by name instead — it is faster than browsing.',
        'jump' => 'Jump to an era',
        'sets' => 'sets',
        'instock' => 'in stock',
        'none' => 'None in stock',
    ],
    'fr' => [
        'title' => 'Parcourir les extensions Pokémon',
        'meta' => 'Toutes les extensions du JCC Pokémon, du Set de Base à la série actuelle, par ère.',
        'intro' => 'Toutes les extensions du JCC Pokémon, regroupées par ère. Vous cherchez une carte précise ? '
            . 'Utilisez plutôt la recherche par nom, c\'est plus rapide.',
        'jump' => 'Aller à une ère',
        'sets' => 'extensions',
        'instock' => 'en stock',
        'none' => 'Aucune en stock',
    ],
];

$contentByLang = [];
$totalSets = 0;
$extras = setExtras();

foreach ($languages as $language) {
    $idLang = (int) $language['id_lang'];
    $strings = str_starts_with((string) $language['locale'], 'fr') ? $copy['fr'] : $copy['en'];

    $eras = childCategories($singlesId, $idLang);
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);

    $html = '<div class="cc-sets">';
    $html .= '<p class="cc-sets__intro">' . $e($strings['intro']) . '</p>';

    // Era jump-nav, so the page is usable without scrolling through 15 sections.
    $html .= '<div class="cc-sets__jump"><span class="cc-sets__jump-label">'
        . $e($strings['jump']) . '</span>';
    foreach ($eras as $era) {
        $html .= '<a class="cc-sets__jump-link" href="#era-' . (int) $era['id_category'] . '">'
            . $e($era['name']) . '</a>';
    }
    $html .= '</div>';

    foreach ($eras as $era) {
        $eraId = (int) $era['id_category'];
        $sets = childCategories($eraId, $idLang);
        $eraUrl = $link->getCategoryLink($eraId, $era['link_rewrite'], $idLang);
        $eraStock = 0;
        foreach ($sets as $set) {
            $eraStock += $extras[(int) $set['id_category']]['stock'] ?? 0;
        }

        $html .= '<div class="cc-sets__era" id="era-' . $eraId . '">';
        $html .= '<div class="cc-sets__era-head">'
            . '<h2 class="cc-sets__era-title"><a href="' . $e($eraUrl) . '">' . $e($era['name']) . '</a></h2>'
            . '<span class="cc-sets__era-meta">' . count($sets) . ' ' . $e($strings['sets'])
            . ($eraStock > 0 ? ' &middot; ' . $eraStock . ' ' . $e($strings['instock']) : '')
            . '</span></div>';

        $html .= '<div class="cc-sets__grid">';
        foreach ($sets as $set) {
            $setId = (int) $set['id_category'];
            $setUrl = $link->getCategoryLink($setId, $set['link_rewrite'], $idLang);
            $stock = $extras[$setId]['stock'] ?? 0;
            $hasLogo = file_exists(_PS_CAT_IMG_DIR_ . $setId . '.jpg');

            // Every tile is the same size. Sets without a logo get a typographic
            // plate built from the TCGplayer abbreviation, not a shrunken card or
            // an empty box - a ragged grid was the single worst thing about the
            // old list.
            $art = $hasLogo
                ? '<img class="cc-set__logo" src="' . $e(_THEME_CAT_DIR_ . $setId . '.jpg') . '" alt="">'
                : '<span class="cc-set__plate">'
                    . $e($extras[$setId]['abbr'] ?? '' ?: setInitials((string) $set['name']))
                    . '</span>';

            $html .= '<a class="cc-set' . ($stock > 0 ? ' cc-set--stocked' : '') . '" href="' . $e($setUrl) . '">'
                . '<span class="cc-set__art">' . $art . '</span>'
                . '<span class="cc-set__name">' . $e($set['name']) . '</span>'
                . '<span class="cc-set__meta">'
                . ($stock > 0 ? $stock . ' ' . $e($strings['instock']) : $e($strings['none']))
                . '</span></a>';

            if ($idLang === $defaultLang) {
                ++$totalSets;
            }
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';

    $contentByLang[$idLang] = ['strings' => $strings, 'html' => $html];
}

// ---------------------------------------------------------------------------
// upsert the CMS page
// ---------------------------------------------------------------------------
// No "LIMIT 1" here: getValue() goes through getRow(), which appends its own, and
// "LIMIT 1 LIMIT 1" silently returns false - which would create a duplicate page
// on every run.
$existing = Db::getInstance()->getValue(
    'SELECT c.id_cms FROM ' . _DB_PREFIX_ . 'cms c
       JOIN ' . _DB_PREFIX_ . 'cms_lang cl ON cl.id_cms = c.id_cms
      WHERE cl.link_rewrite = "' . pSQL(PAGE_REWRITE) . '"'
);

$cms = $existing ? new CMS((int) $existing) : new CMS();
$cms->active = true;
$cms->indexation = true;
$cms->id_cms_category = 1;

foreach ($contentByLang as $idLang => $payload) {
    $cms->meta_title[$idLang] = $payload['strings']['title'];
    $cms->meta_description[$idLang] = $payload['strings']['meta'];
    $cms->link_rewrite[$idLang] = PAGE_REWRITE;
    $cms->content[$idLang] = $payload['html'];
}

if ($existing) {
    $cms->update();
    line('set directory page updated (id ' . (int) $cms->id . ", $totalSets sets)");
} else {
    $cms->add();
    line('set directory page created (id ' . (int) $cms->id . ", $totalSets sets)");
}

// ---------------------------------------------------------------------------
// menu: the game, then the set directory
// ---------------------------------------------------------------------------
/**
 * Two items, and that is the whole menu.
 *
 * The shop sells one game. A second top-level entry for a product line that does
 * not exist (Supplies & Accessories) was half the navigation pointing at nothing.
 *
 * "Browse Pokémon Sets" stays top-level while Pokémon is the only game; when a
 * second TCG arrives it moves under its game and each game gets its own set
 * browser, because a set list is meaningless across games.
 */
$menuItems = [];
$pokemonMenuId = findByName($homeId, 'Pokémon', $defaultLang);
if ($pokemonMenuId) {
    $menuItems[] = 'CAT' . $pokemonMenuId;
}
$menuItems[] = 'CMS' . (int) $cms->id;

Configuration::updateValue('MOD_BLOCKTOPMENU_ITEMS', implode(',', $menuItems));
line('menu: ' . implode(', ', $menuItems));

Tools::clearAllCache();
Tools::clearSf2Cache();
line('caches cleared');
