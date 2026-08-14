<?php
/**
 * Builds the Meilisearch index for DoubleSleeve.
 *
 * PrestaShop's native search is a MySQL LIKE/fulltext query: no typo tolerance, no
 * synonyms, no instant results. Card buyers mistype constantly ("charzard") and use
 * trade slang that matches no official field ("alt art", "etb", "psa10"), so the
 * built-in search fails the most common queries. This indexes everything into
 * Meilisearch, which handles all three.
 *
 * One index with a `type` discriminator rather than several, so a single search box
 * can return cards, sets and Pokémon together and rank them against each other.
 *
 * Idempotent: settings are declarative, documents are upserted by primary key.
 *
 *   make search-index
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const MEILI_HOST = 'http://meilisearch:7700';
/**
 * One index PER STOREFRONT LANGUAGE.
 *
 * A single index meant the French storefront searched English documents: typing
 * "dracaufeu" or "etincelles" found nothing, and every hit that did come back
 * rendered its English name and English set under French chrome. The documents
 * are the catalogue in one language, so there has to be one per language.
 */
function indexFor(string $iso): string
{
    return 'catalog_' . strtolower($iso);
}
const BATCH = 500;

function line(string $s): void { echo "   + $s\n"; }
function fail(string $s): void { echo "   ! $s\n"; }

/** Minimal Meilisearch client - one dependency-free helper beats pulling in an SDK. */
function meili(string $method, string $path, $payload = null): array
{
    $key = getenv('MEILI_MASTER_KEY') ?: '';
    $ch = curl_init(MEILI_HOST . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $key !== '' ? 'Authorization: Bearer ' . $key : null,
        ]),
        CURLOPT_POSTFIELDS => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['_status' => 0, '_error' => $error];
    }

    return ((array) json_decode((string) $body, true)) + ['_status' => $status];
}

echo "\n\033[1m== Meilisearch index\033[0m\n";

$health = meili('GET', '/health');
if (($health['status'] ?? '') !== 'available') {
    fail('Meilisearch unreachable at ' . MEILI_HOST . ' - is the container running?');
    exit(1);
}
line('connected to Meilisearch');

// ---------------------------------------------------------------------------
// settings
// ---------------------------------------------------------------------------
// Order of searchableAttributes IS the relevance order: a name match must beat a
// set match, which must beat an artist match.
$settings = [
    'searchableAttributes' => [
        'name', 'pokemon', 'set_name', 'set_code', 'card_number',
        'rarity', 'artist', 'section', 'keywords',
    ],
    'filterableAttributes' => [
        'type', 'game', 'section', 'set_name', 'set_code', 'series', 'rarity',
        'pokemon', 'card_type', 'stage', 'regulation_mark', 'finish', 'condition',
        'language', 'grading_company', 'grade', 'sealed_type', 'print_region',
        'brand', 'in_stock', 'price', 'release_year',
    ],
    'sortableAttributes' => ['price', 'release_date', 'name', 'dex_number', 'boost'],
    // boost:desc breaks ties toward things you can actually buy: a real card
    // outranks the "browse all Charizards" shortcut when both match equally.
    'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'boost:desc'],
    'typoTolerance' => [
        'enabled' => true,
        // "ex" and "gx" are real card suffixes - never treat them as typos.
        'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
        'disableOnWords' => ['ex', 'gx', 'v', 'vmax', 'vstar', 'psa', 'bgs', 'cgc'],
    ],
    'synonyms' => [
        // Trade slang that matches no official field name.
        'alt art' => ['Illustration Rare', 'Special Illustration Rare'],
        'altart' => ['Illustration Rare', 'Special Illustration Rare'],
        'ir' => ['Illustration Rare'],
        'sir' => ['Special Illustration Rare'],
        'secret rare' => ['Hyper Rare', 'Secret Rare'],
        'rainbow' => ['Hyper Rare', 'Rainbow Rare'],
        'gold' => ['Hyper Rare'],
        'full art' => ['Ultra Rare', 'Full Art'],
        'etb' => ['Elite Trainer Box'],
        'bb' => ['Booster Box'],
        'nm' => ['Near Mint'],
        'lp' => ['Lightly Played'],
        'mp' => ['Moderately Played'],
        'hp' => ['Heavily Played'],
        'reverse' => ['Reverse Holofoil'],
        'rh' => ['Reverse Holofoil'],
        'first edition' => ['1st Edition'],
        '1st ed' => ['1st Edition'],
        // Japanese names, for JP-market buyers searching in their own language.
        'リザードン' => ['Charizard'],
        'ピカチュウ' => ['Pikachu'],
        'イーブイ' => ['Eevee'],
        'ミュウツー' => ['Mewtwo'],
    ],
];

$totals = [];

foreach (Language::getLanguages(false) as $language) {
    $idLang = (int) $language['id_lang'];
    $indexUid = indexFor((string) $language['iso_code']);
    echo "\n   \033[1m-- $indexUid\033[0m\n";

    meili('POST', '/indexes', ['uid' => $indexUid, 'primaryKey' => 'doc_id']);

foreach ($settings as $key => $value) {
    $endpoint = '/indexes/' . $indexUid . '/settings/' . preg_replace_callback(
        '/[A-Z]/',
        static fn ($m) => '-' . strtolower($m[0]),
        $key
    );
    // Most settings sub-routes are PUT, but typo-tolerance only accepts PATCH.
    $method = $key === 'typoTolerance' ? 'PATCH' : 'PUT';
    $result = meili($method, $endpoint, $value);
    if (($result['_status'] ?? 0) >= 400) {
        fail("settings/$key failed: " . json_encode($result));
    }
}
line('settings applied (typo tolerance, ' . count($settings['synonyms']) . ' synonym groups)');

// ---------------------------------------------------------------------------
// documents
// ---------------------------------------------------------------------------
$linker = Context::getContext()->link;
$documents = [];

// --- sets ------------------------------------------------------------------
// So "obsidian flames" and "obf" land on the set page even with zero products.
$sets = Db::getInstance()->executeS(
    'SELECT c.id_category, cl.name, cl.link_rewrite, p.id_category AS id_series, pl.name AS series
       FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . '
       JOIN ' . _DB_PREFIX_ . 'category p ON p.id_category = c.id_parent
       JOIN ' . _DB_PREFIX_ . 'category_lang pl ON pl.id_category = p.id_category AND pl.id_lang = ' . $idLang . '
      WHERE c.level_depth = 5 AND c.active = 1'
) ?: [];

foreach ($sets as $set) {
    $name = (string) $set['name'];
    // Set categories are stored as "Obsidian Flames (OBF)"; split for clean fields.
    $code = preg_match('/\(([^)]+)\)$/', $name, $m) ? $m[1] : '';
    $plain = trim(preg_replace('/\s*\([^)]*\)$/', '', $name));

    $documents[] = [
        'doc_id' => 'set-' . (int) $set['id_category'],
        'type' => 'set',
        'boost' => 2,
        'game' => 'Pokémon',
        'section' => 'Singles',
        'name' => $plain,
        'set_name' => $plain,
        'set_code' => $code,
        'series' => (string) $set['series'],
        'keywords' => trim("$plain $code " . $set['series']),
        'url' => $linker->getCategoryLink((int) $set['id_category'], $set['link_rewrite'], $idLang),
        'image' => $linker->getCatImageLink($set['link_rewrite'], (int) $set['id_category'], 'category_default'),
    ];
}
line(count($sets) . ' sets queued');

// --- Pokémon ---------------------------------------------------------------
// "charizard" should return something useful before any Charizard is in stock.
$speciesRows = Db::getInstance()->executeS(
    'SELECT fvl.id_feature_value, fvl.value
       FROM ' . _DB_PREFIX_ . 'feature_value_lang fvl
       JOIN ' . _DB_PREFIX_ . 'feature_value fv ON fv.id_feature_value = fvl.id_feature_value
       -- The FEATURE is resolved by its English name at id_lang 1 and its VALUES
       -- read in the target language. Matching fl.name against the current
       -- language returned nothing in French, where the feature is "Pokémon".
       JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON fl.id_feature = fv.id_feature AND fl.id_lang = 1
      WHERE fl.name = "Pokemon" AND fvl.id_lang = ' . $idLang
) ?: [];

// Note: no "LIMIT 1" in this SQL - Db::getRow() appends its own, and a query that
// already has one becomes "LIMIT 1 LIMIT 1", which silently returns false.
$singlesUrl = '';
// Same trap: the category is found by its English name, then read in the target
// language. "Singles" is "Cartes à l'unité" in French, so matching the localised
// name found no category and every Pokémon deep link came out relative.
$singlesRow = Db::getInstance()->getRow(
    'SELECT c.id_category, cl.link_rewrite FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang en
            ON en.id_category = c.id_category AND en.id_lang = 1 AND en.name = "Singles"
       JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang
);
if ($singlesRow) {
    $singlesUrl = $linker->getCategoryLink((int) $singlesRow['id_category'], $singlesRow['link_rewrite'], $idLang);
} else {
    fail('Singles category not found - Pokémon deep links will be relative');
}

foreach ($speciesRows as $species) {
    $documents[] = [
        'doc_id' => 'pkm-' . (int) $species['id_feature_value'],
        'type' => 'pokemon',
        'boost' => 1,
        'game' => 'Pokémon',
        'section' => 'Singles',
        'name' => (string) $species['value'],
        'pokemon' => (string) $species['value'],
        'keywords' => (string) $species['value'],
        // Deep-links into the faceted search for that Pokémon.
        'url' => $singlesUrl . '?q=Pokemon-' . rawurlencode((string) $species['value']),
    ];
}
line(count($speciesRows) . ' Pokémon queued');

// --- products --------------------------------------------------------------
$productRows = Db::getInstance()->executeS(
    'SELECT p.id_product, pl.name, pl.link_rewrite, p.price, p.active,
            IFNULL(sa.quantity, 0) AS quantity,
            (SELECT i.id_image FROM ' . _DB_PREFIX_ . 'image i
              WHERE i.id_product = p.id_product ORDER BY i.cover DESC, i.position ASC LIMIT 1) AS id_image
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . '
       LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
              ON sa.id_product = p.id_product AND sa.id_product_attribute = 0
      WHERE p.active = 1'
) ?: [];

/**
 * Feature values keyed by the feature's ENGLISH name, with the VALUE in the target
 * language.
 *
 * getFrontFeatures($idLang) returns localised keys as well as localised values, so
 * every $flat('Set') / $flat('Rarity') lookup below silently missed on the French
 * pass - the feature is called "Extension" there. French documents were indexed
 * with no set, no rarity and no Pokémon at all, which is most of what makes a card
 * findable.
 */
$featuresByProduct = [];
foreach (Db::getInstance()->executeS(
    'SELECT fp.id_product, en.name AS feature, fvl.value
       FROM ' . _DB_PREFIX_ . 'feature_product fp
       JOIN ' . _DB_PREFIX_ . 'feature_lang en ON en.id_feature = fp.id_feature AND en.id_lang = 1
       JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
            ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . $idLang
) ?: [] as $row) {
    $featuresByProduct[(int) $row['id_product']][(string) $row['feature']][] = (string) $row['value'];
}

foreach ($productRows as $row) {
    $productId = (int) $row['id_product'];

    $features = $featuresByProduct[$productId] ?? [];
    $flat = static fn (string $key) => $features[$key][0] ?? null;

    $documents[] = array_filter([
        'doc_id' => 'prd-' . $productId,
        'type' => 'product',
        'boost' => 3,
        'game' => 'Pokémon',
        'name' => (string) $row['name'],
        'pokemon' => $flat('Pokemon'),
        'set_name' => $flat('Set'),
        'card_number' => $flat('Card Number'),
        'rarity' => $flat('Rarity'),
        'artist' => $flat('Artist'),
        'card_type' => $flat('Card Type'),
        'stage' => $flat('Stage'),
        'regulation_mark' => $flat('Regulation Mark'),
        'grading_company' => $flat('Grading Company'),
        'grade' => $flat('Grade'),
        'sealed_type' => $flat('Sealed Product Type'),
        'print_region' => $flat('Print Region'),
        'brand' => $flat('Brand'),
        'release_year' => $flat('Release Year'),
        'price' => (float) $row['price'],
        'in_stock' => ((int) $row['quantity']) > 0,
        'url' => $linker->getProductLink($productId, $row['link_rewrite'], null, null, $idLang),
        'image' => $row['id_image']
            ? $linker->getImageLink($row['link_rewrite'], (string) $row['id_image'], 'small_default')
            : null,
    ], static fn ($v) => $v !== null);
}
line(count($productRows) . ' products queued');

// ---------------------------------------------------------------------------
// push
// ---------------------------------------------------------------------------
$pushed = 0;
foreach (array_chunk($documents, BATCH) as $chunk) {
    $result = meili('POST', '/indexes/' . $indexUid . '/documents', $chunk);
    if (($result['_status'] ?? 0) >= 400) {
        fail('batch failed: ' . json_encode($result));
        continue;
    }
    $pushed += count($chunk);
}
line("$pushed documents pushed in batches of " . BATCH);

// Meilisearch indexes asynchronously; wait so the run is verifiable on exit.
for ($i = 0; $i < 60; ++$i) {
    $stats = meili('GET', '/indexes/' . $indexUid . '/stats');
    if (empty($stats['isIndexing'])) {
        line('index built: ' . ($stats['numberOfDocuments'] ?? 0) . ' documents searchable');
        $totals[$indexUid] = (int) ($stats['numberOfDocuments'] ?? 0);
        break;
    }
    sleep(1);
}

}   // per-language index

echo "\n";
foreach ($totals as $uid => $count) {
    line("$uid: $count documents");
}
