<?php
/**
 * Point the storefront at the DoubleSleeve catalogue and clear PrestaShop's demo
 * homepage content.
 *
 * Without this the shop still works, but the front door is unusable: the top menu
 * is configured with the demo category IDs (which purge-demo deletes, so it renders
 * nothing at all) and the homepage is the stock "Sample 1/2/3" slider plus a lorem
 * ipsum text block.
 *
 * Idempotent. Run via `make provision`.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

function line(string $s): void { echo "   + $s\n"; }

echo "\n\033[1m== Storefront\033[0m\n";

$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
$homeId = (int) Configuration::get('PS_HOME_CATEGORY');

// The top menu is owned by pages.php, which runs after this and needs the set
// directory page ID to include it.

// --- homepage slider -------------------------------------------------------
// The three "Sample" slides ship with placeholder stock photography; leaving them
// up makes the shop read as an unfinished PrestaShop install.
$slides = Db::getInstance()->executeS('SELECT id_homeslider_slides FROM ' . _DB_PREFIX_ . 'homeslider_slides');
$removed = 0;
foreach ($slides as $slide) {
    Db::getInstance()->delete('homeslider_slides_lang', 'id_homeslider_slides = ' . (int) $slide['id_homeslider_slides']);
    Db::getInstance()->delete('homeslider', 'id_slide = ' . (int) $slide['id_homeslider_slides']);
    Db::getInstance()->delete('homeslider_slides', 'id_homeslider_slides = ' . (int) $slide['id_homeslider_slides']);
    ++$removed;
}
line("demo slider slides removed: $removed");

// --- homepage text block ---------------------------------------------------
// ps_customtext keeps its content in the `info` table.
/**
 * Homepage hero. Written as markup rather than a slider: a card shop's front page
 * should get people into the catalogue, not autoplay stock photography.
 */
/**
 * The four homepage figures.
 *
 * Deliberately about the COLLECTION rather than the shop: how many cards are here,
 * how much of the catalogue they span, and how far back they go. "5 condition
 * grades" and "CAD/USD" were not statistics, they were features listed in a slot
 * meant for numbers that change.
 *
 * @return array{cards:int, sets:int, sealed:int, oldest:int}
 */
function homepageStats(): array
{
    $db = Db::getInstance();

    return [
        // Physical cards on the shelf, not distinct listings.
        'cards' => (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'card_copy WHERE status = "available"'
        ),
        // Sets actually REPRESENTED. The catalogue knows 217; claiming those as
        // stock would be a lie told in the largest type on the page.
        'sets' => (int) $db->getValue(
            'SELECT COUNT(DISTINCT c.id_category)
               FROM ' . _DB_PREFIX_ . 'category c
               JOIN ' . _DB_PREFIX_ . 'category_product cp ON cp.id_category = c.id_category
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0
              WHERE c.level_depth = 5 AND c.active = 1'
        ),
        'sealed' => (int) $db->getValue(
            'SELECT IFNULL(SUM(sa.quantity), 0)
               FROM ' . _DB_PREFIX_ . 'category_product cp
               JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON cl.id_category = cp.id_category AND cl.id_lang = 1 AND cl.name = "Sealed"
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0'
        ),
        'oldest' => (int) $db->getValue(
            'SELECT MIN(YEAR(g.published_on))
               FROM ' . _DB_PREFIX_ . 'tcg_group_category g
               JOIN ' . _DB_PREFIX_ . 'category_product cp ON cp.id_category = g.id_category
               JOIN ' . _DB_PREFIX_ . 'stock_available sa
                    ON sa.id_product = cp.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0'
        ),
    ];
}

/**
 * Background artwork for one homepage tile, as an inline custom property.
 *
 * Written into the CMS markup rather than applied from JS because the tiles are
 * above the fold: a background that arrives after first paint is a visible flash.
 * Empty string when there is no artwork, so the tile renders exactly as before.
 */
function tileArt(string $key): string
{
    if ($key === '' || $key === '0') {
        return '';
    }
    $file = _PS_IMG_DIR_ . 'nav/' . $key . '.png';

    return file_exists($file)
        ? ' style="--cc-tile-image:url(' . _PS_IMG_ . 'nav/' . $key . '.png)"'
        : '';
}

function heroHtml(array $t, array $links, array $art): string
{
    return <<<HTML
<section class="cc-hero">
  <div class="cc-hero__glow"></div>
  <p class="cc-hero__eyebrow">{$t['eyebrow']}</p>
  <h1 class="cc-hero__title">{$t['title_a']}<span class="cc-hero__foil">{$t['title_b']}</span></h1>
  <p class="cc-hero__sub">{$t['sub']}</p>
  <div class="cc-hero__actions">
    <a class="cc-btn cc-btn--primary" href="{$links['singles']}">{$t['cta_singles']}</a>
    <a class="cc-btn cc-btn--ghost" href="{$links['sealed']}">{$t['cta_sealed']}</a>
    <a class="cc-btn cc-btn--ghost" href="{$links['sets']}">{$t['cta_sets']}</a>
  </div>
  <ul class="cc-stats">
    <li><strong data-cc-stat="cards">{$t['stat1_v']}</strong><span>{$t['stat1_l']}</span></li>
    <li><strong data-cc-stat="sets">{$t['stat2_v']}</strong><span>{$t['stat2_l']}</span></li>
    <li><strong data-cc-stat="sealed">{$t['stat3_v']}</strong><span>{$t['stat3_l']}</span></li>
    <li><strong data-cc-stat="oldest">{$t['stat4_v']}</strong><span>{$t['stat4_l']}</span></li>
  </ul>
</section>

<section class="cc-tiles">
  <a class="cc-tile cc-tile--singles" href="{$links['singles']}"{$art['singles']}>
    <span class="cc-tile__label">{$t['tile1']}</span>
    <span class="cc-tile__meta">{$t['tile1_m']}</span>
  </a>
  <a class="cc-tile cc-tile--sealed" href="{$links['sealed']}"{$art['sealed']}>
    <span class="cc-tile__label">{$t['tile2']}</span>
    <span class="cc-tile__meta">{$t['tile2_m']}</span>
  </a>
  <a class="cc-tile cc-tile--graded" href="{$links['graded']}"{$art['graded']}>
    <span class="cc-tile__label">{$t['tile3']}</span>
    <span class="cc-tile__meta">{$t['tile3_m']}</span>
  </a>
  <a class="cc-tile cc-tile--sets" href="{$links['sets']}"{$art['sets']}>
    <span class="cc-tile__label">{$t['tile4']}</span>
    <span class="cc-tile__meta">{$t['tile4_m']}</span>
  </a>
</section>
HTML;
}

/**
 * Seed values only.
 *
 * These numbers are baked into a CMS block, so whatever is written here is frozen
 * at provisioning time - the homepage was advertising 391 sets catalogued when the
 * catalogue held 217. The live figures are recomputed per request by
 * cryptocards_theme and written into the [data-cc-stat] slots on load; these are
 * just what a visitor sees for the instant before that happens.
 */
$stats = homepageStats();

$homeId = (int) Configuration::get('PS_HOME_CATEGORY');
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

function categoryIdByName(string $name): int
{
    return (int) Db::getInstance()->getValue(
        'SELECT cl.id_category FROM ' . _DB_PREFIX_ . 'category_lang cl
          WHERE cl.id_lang = 1 AND cl.name = "' . pSQL($name) . '"'
    );
}

function catLink(string $name, int $idLang): string
{
    $row = Db::getInstance()->getRow(
        'SELECT c.id_category, cl.link_rewrite FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $idLang . '
          WHERE cl.name = "' . pSQL($name) . '"'
    );

    return $row
        ? Context::getContext()->link->getCategoryLink((int) $row['id_category'], $row['link_rewrite'], $idLang)
        : '#';
}

/**
 * Homepage copy.
 *
 * One person selling from one collection, and it should read that way. The
 * previous version claimed every card was graded (most are raw, condition-graded
 * by hand) and advertised a supplies range that does not exist, so it was both
 * grander and less accurate than the truth.
 *
 * No em dashes.
 */
$intro = [
    'en' => [
        'eyebrow' => 'Pokémon TCG · one collector, shipping from Canada',
        /**
         * Echoes the shop name, and states the care standard in four words.
         * Double sleeving is what a careful collector does, so it reads as
         * competence to anyone who knows and as a plain fact to anyone who does
         * not. "Sold once" is the quiet half: these are single copies, not stock.
         */
        'title_a' => 'Sleeved twice, ',
        'title_b' => 'sold once.',
        'sub' => 'Cards I have spent years pulling, buying and sorting. Every one is checked by '
            . 'hand, graded to TCGplayer condition standards, and packed the way I would want '
            . 'to receive it. Browse by set, rarity, Pokémon or condition.',
        'cta_singles' => 'Shop singles',
        'cta_sealed' => 'Sealed product',
        'cta_sets' => 'Browse sets',
        'stat1_v' => number_format($stats['cards']), 'stat1_l' => 'cards on the shelf',
        'stat2_v' => number_format($stats['sets']),  'stat2_l' => 'sets represented',
        'stat3_v' => number_format($stats['sealed']), 'stat3_l' => 'sealed items',
        'stat4_v' => $stats['oldest'] ?: '1999', 'stat4_l' => 'oldest set here',
        'tile1' => 'Singles', 'tile1_m' => 'Base Set through Mega Evolution',
        'tile2' => 'Sealed', 'tile2_m' => 'Boxes, bundles, packs',
        'tile3' => 'Graded', 'tile3_m' => 'The few that were worth slabbing',
        'tile4' => 'Sets', 'tile4_m' => 'Every set, era by era',
    ],
    'fr' => [
        'eyebrow' => 'JCC Pokémon · un collectionneur, expédié du Canada',
        'title_a' => 'Protégées deux fois, ',
        'title_b' => 'vendues une seule.',
        'sub' => 'Des cartes que j\'ai passé des années à ouvrir, acheter et classer. Chacune est '
            . 'vérifiée à la main, évaluée selon les standards TCGplayer, et emballée comme '
            . 'j\'aimerais la recevoir. Parcourez par extension, rareté, Pokémon ou état.',
        'cta_singles' => 'Voir les cartes',
        'cta_sealed' => 'Produits scellés',
        'cta_sets' => 'Extensions',
        'stat1_v' => number_format($stats['cards']), 'stat1_l' => 'cartes en tablette',
        'stat2_v' => number_format($stats['sets']),  'stat2_l' => 'extensions représentées',
        'stat3_v' => number_format($stats['sealed']), 'stat3_l' => 'articles scellés',
        'stat4_v' => $stats['oldest'] ?: '1999', 'stat4_l' => 'extension la plus ancienne',
        'tile1' => 'À l\'unité', 'tile1_m' => 'Du Set de Base à Méga-Évolution',
        'tile2' => 'Scellé', 'tile2_m' => 'Displays, lots, boosters',
        'tile3' => 'Gradées', 'tile3_m' => 'Les quelques-unes qui le méritaient',
        'tile4' => 'Extensions', 'tile4_m' => 'Toutes les extensions, ère par ère',
    ],
];

$updated = 0;
foreach (Language::getLanguages(false) as $lang) {
    $idLang = (int) $lang['id_lang'];
    $strings = str_starts_with((string) $lang['locale'], 'fr') ? $intro['fr'] : $intro['en'];
    $links = [
        'singles' => catLink('Singles', $idLang),
        'sealed' => catLink('Sealed', $idLang),
        'graded' => catLink('Graded', $idLang),
        'sets' => Context::getContext()->link->getCMSLink(
            (int) Db::getInstance()->getValue(
                'SELECT c.id_cms FROM ' . _DB_PREFIX_ . 'cms c
                   JOIN ' . _DB_PREFIX_ . 'cms_lang cl ON cl.id_cms = c.id_cms
                  WHERE cl.link_rewrite = "pokemon-sets"'
            ) ?: 1,
            null,
            null,
            $idLang
        ),
    ];

    $art = [
        'singles' => tileArt((string) categoryIdByName('Singles')),
        'sealed' => tileArt((string) categoryIdByName('Sealed')),
        'graded' => tileArt((string) categoryIdByName('Graded')),
        'sets' => tileArt('sets'),
    ];

    $updated += (int) Db::getInstance()->update(
        'info_lang',
        ['text' => pSQL(heroHtml($strings, $links, $art), true)],
        'id_lang = ' . $idLang
    );
}
line("homepage hero rebuilt for $updated language(s)");

// --- featured products on the homepage -------------------------------------
// Point the featured block at Singles so the front page shows real stock.
$singlesId = (int) Db::getInstance()->getValue(
    'SELECT c.id_category FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $defaultLang . '
      WHERE cl.name = "Singles"'
);
if ($singlesId) {
    Configuration::updateValue('HOME_FEATURED_CAT', $singlesId);
    Configuration::updateValue('HOME_FEATURED_NBR', 8);
    Configuration::updateValue('HOME_FEATURED_RANDOMIZE', 1);
    line('featured products block pointed at Singles (8 products)');
}

// --- trust blocks ----------------------------------------------------------
// ps_customer_reassurance ships with "(edit with the Customer Reassurance module)"
// placeholders on every product page. For a shop selling four-figure singles, the
// shipping and grading promises are part of the sell, not boilerplate.
$reassurance = [
    'en' => [
        ['Graded to TCGplayer standards', 'Every single is condition-checked by hand before listing.'],
        ['Sleeved, toploaded, tracked', 'Orders ship in a penny sleeve and toploader. Tracked on every order.'],
        ['14-day returns', 'Not as described? Send it back within 14 days for a full refund.'],
    ],
    'fr' => [
        ['Évaluées selon les standards TCGplayer', 'Chaque carte est vérifiée à la main avant sa mise en vente.'],
        ['Protégée, toploader, suivi', 'Envoi en pochette et toploader. Numéro de suivi sur chaque commande.'],
        ['Retours sous 14 jours', 'Non conforme ? Retournez-la sous 14 jours pour un remboursement complet.'],
    ],
];

$reassuranceRows = Db::getInstance()->executeS(
    'SELECT id_psreassurance FROM ' . _DB_PREFIX_ . 'psreassurance ORDER BY id_psreassurance'
) ?: [];

$blockIndex = 0;
foreach ($reassuranceRows as $row) {
    foreach (Language::getLanguages(false) as $language) {
        $set = str_starts_with((string) $language['locale'], 'fr') ? $reassurance['fr'] : $reassurance['en'];
        if (!isset($set[$blockIndex])) {
            continue;
        }
        [$title, $description] = $set[$blockIndex];
        Db::getInstance()->update(
            'psreassurance_lang',
            ['title' => pSQL($title), 'description' => pSQL($description), 'link' => ''],
            'id_psreassurance = ' . (int) $row['id_psreassurance'] . ' AND id_lang = ' . (int) $language['id_lang']
        );
    }
    ++$blockIndex;
}
line("trust blocks rewritten: $blockIndex");

// --- demo banner -----------------------------------------------------------
// ps_banner ships a "20% OFF ON CLOTHES" creative on the homepage. Disable the
// module rather than blanking the image, so it can be re-enabled with real art.
$banner = Module::getInstanceByName('ps_banner');
if ($banner && Module::isEnabled('ps_banner')) {
    $banner->disable();
    line('ps_banner disabled (demo "20% off clothes" creative)');
}

// --- caches ----------------------------------------------------------------
// clearSmartyCache() alone is not enough: the menu keeps rendering the old
// category set until the Symfony cache in var/cache is dropped too.
Tools::clearAllCache();
Tools::clearSf2Cache();
line('all caches cleared (first page load after this is slow)');
