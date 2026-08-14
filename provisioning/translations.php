<?php
/**
 * Fills the fr-CA gaps the installed translation packs leave behind.
 *
 * PrestaShop falls back to the SOURCE string when a catalogue has no entry, so a
 * missing translation is not an error anywhere - it just renders English inside
 * otherwise-French chrome, and only someone reading /qc/ will ever notice. The
 * Hummingbird theme ships several of these.
 *
 * Two different mechanisms, because they are two different kinds of string:
 *
 *   - THEME AND MODULE strings go into the `translation` table, which is the
 *     front-office override layer and beats the pack files.
 *   - LINK BLOCK titles are shop CONTENT, not translations - they live in
 *     link_block_lang and were written in English into every language at install.
 *
 * Idempotent.
 *
 *   make translations
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const THEME = 'hummingbird';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== fr-CA translation gaps\033[0m\n";

$db = Db::getInstance();

$frenchId = 0;
foreach (Language::getLanguages(false) as $language) {
    if (stripos((string) $language['language_code'], 'fr') === 0
        || strtolower((string) $language['iso_code']) === 'qc') {
        $frenchId = (int) $language['id_lang'];
        break;
    }
}
if (!$frenchId) {
    warn('no French language installed - nothing to do');

    return;
}
line("French language id: $frenchId");

/**
 * domain => [source string => fr-CA].
 *
 * Every one of these was observed rendering English on the French storefront.
 * "results" and "Rated" are screen-reader-only, which is exactly why they had gone
 * unnoticed: a French screen-reader user is the only person they reach.
 */
const STRINGS = [
    'ShopThemeGlobal' => [
        'Skip to main content' => 'Aller au contenu principal',
        'result' => 'résultat',
        'results' => 'résultats',
        'Toggle store information' => 'Afficher les informations de la boutique',
        'Toggle %s links' => 'Afficher les liens %s',
    ],
    'ShopThemeCatalog' => [
        'Search products...' => 'Rechercher des produits...',
    ],
    'ShopThemeCustomeraccount' => [
        'Toggle your account links' => 'Afficher les liens de votre compte',
    ],
    'ModulesProductcommentsShop' => [
        'Rated' => 'Noté',
        'out of 5 stars' => 'sur 5 étoiles',
        'out of 5 stars based on ' => 'sur 5 étoiles, sur la base de ',
        'review(s)' => 'avis',
        'Rated %average_grade% out of 5 stars. Go to reviews section'
            => 'Noté %average_grade% sur 5 étoiles. Aller à la section des avis',
        'Be the first to write your review' => 'Soyez le premier à donner votre avis',
        'Write your review' => 'Donnez votre avis',
        'No customer reviews for the moment.' => 'Aucun avis client pour le moment.',
        'Read user reviews' => 'Lire les avis des clients',
    ],
];

$written = 0;
$already = 0;

foreach (STRINGS as $domain => $entries) {
    foreach ($entries as $source => $french) {
        $existing = (string) $db->getValue(
            'SELECT translation FROM ' . _DB_PREFIX_ . 'translation
              WHERE id_lang = ' . $frenchId . ' AND domain = "' . pSQL($domain) . '"
                AND `key` = "' . pSQL($source) . '"'
        );
        if ($existing === $french) {
            ++$already;
            continue;
        }

        if ($existing !== '') {
            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'translation SET translation = "' . pSQL($french) . '"
                  WHERE id_lang = ' . $frenchId . ' AND domain = "' . pSQL($domain) . '"
                    AND `key` = "' . pSQL($source) . '"'
            );
        } else {
            $db->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'translation (id_lang, `key`, translation, domain, theme)
                 VALUES (' . $frenchId . ', "' . pSQL($source) . '", "' . pSQL($french) . '",
                         "' . pSQL($domain) . '", "' . pSQL(THEME) . '")'
            );
        }
        ++$written;
    }
}
line("theme/module strings written: $written (already correct: $already)");

// ---------------------------------------------------------------------------
// footer link blocks
// ---------------------------------------------------------------------------
/**
 * These are the visible footer column headings. PrestaShop writes the English
 * title into every language at install, so the French footer read "Products" and
 * "Our company" above perfectly French link lists.
 */
const LINK_BLOCKS = [
    'Products' => 'Produits',
    'Our company' => 'Notre entreprise',
];

$renamed = 0;
foreach (LINK_BLOCKS as $english => $french) {
    $ids = $db->executeS(
        'SELECT en.id_link_block FROM ' . _DB_PREFIX_ . 'link_block_lang en
          WHERE en.id_lang = 1 AND en.name = "' . pSQL($english) . '"'
    ) ?: [];
    foreach ($ids as $row) {
        $updated = $db->execute(
            'UPDATE ' . _DB_PREFIX_ . 'link_block_lang SET name = "' . pSQL($french) . '"
              WHERE id_link_block = ' . (int) $row['id_link_block'] . '
                AND id_lang = ' . $frenchId . ' AND name <> "' . pSQL($french) . '"'
        );
        if ($updated && $db->Affected_Rows()) {
            ++$renamed;
        }
    }
}
line("footer link blocks localised: $renamed");

Tools::clearAllCache();
