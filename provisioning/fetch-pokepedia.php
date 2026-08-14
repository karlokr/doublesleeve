<?php
/**
 * Dumps the French set catalogue from Poképédia.
 *
 * Bulbapedia's {{Langtable}} only covers the sets that shipped in several
 * languages, and it is missing entirely on most promo runs, box products and
 * anything released after ~2024 - 106 of our 217 sets got nothing from it.
 *
 * Poképédia is the French-language Pokémon wiki, so every set is already filed
 * under its French name, and each page's Infobox states the English one:
 *
 *     | nom=30e Anniversaire
 *     | nomen=30th Celebration
 *     | série=Méga-Évolution
 *
 * That gives the set name AND the era name in one pass, which is the other half of
 * the problem - "Scarlet & Violet" and "Promos & Specials" were rendering English
 * as era headings.
 *
 * Results are committed to data/pokepedia-sets.csv so provisioning stays offline.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/fetch-pokepedia.php
 *   ... --refresh   re-fetch pages already in the CSV
 */
declare(strict_types=1);

const CACHE_CSV = '/provisioning/data/pokepedia-sets.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const OUT = '/tmp/pokepedia-sets.csv';
const API = 'https://www.pokepedia.fr/api.php';

/** Every category that files a TCG expansion, promo run or box product. */
const CATEGORIES = [
    'Catégorie:Extension du JCC',
    'Catégorie:Extension promotionnelle',
    'Catégorie:Extension japonaise du JCC',
];

/**
 * Poképédia throttles, and it does so by refusing the connection rather than by
 * returning an error document. A first pass at 120ms got about a dozen pages and
 * then silently returned nothing for the remaining 460 - which looked exactly like
 * "these sets have no French name". Slow, and check the status.
 */
const DELAY_US = 700000;

define('REFRESH', in_array('--refresh', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== French set catalogue from Poképédia\033[0m\n";

/**
 * One API call, with backoff.
 *
 * ignore_errors keeps the body of an error response readable; the STATUS is what
 * decides success. Treating a non-empty body as success is how a 404 page full of
 * HTML once got mistaken for a PNG.
 */
function api(array $params, int $attempts = 4): ?array
{
    $url = API . '?' . http_build_query($params + ['format' => 'json']);

    for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
        $context = stream_context_create(['http' => [
            'timeout' => 30,
            'user_agent' => 'DoubleSleeve/1.0 (storefront set-name localisation)',
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body !== false && $status === 200) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !isset($decoded['error'])) {
                return $decoded;
            }
        }
        sleep($attempt * 2);
    }

    return null;
}

/** A named parameter out of a wiki Infobox. */
function field(string $wikitext, string $key): string
{
    if (!preg_match('/\|\s*' . preg_quote($key, '/') . '\s*=\s*([^\n|}]*)/u', $wikitext, $match)) {
        return '';
    }
    // Values carry wiki markup - links, <sup>, templates - none of which belongs
    // in a catalogue name.
    $value = preg_replace('/\[\[([^\]|]*\|)?([^\]]*)\]\]/u', '$2', $match[1]);
    $value = preg_replace('/<[^>]*>/', '', (string) $value);
    $value = preg_replace('/\{\{[^}]*\}\}/', '', (string) $value);
    $value = preg_replace("/'{2,}/", '', (string) $value);

    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

// ---------------------------------------------------------------------------
$cache = [];
if (!REFRESH && is_readable(CACHE_CSV)) {
    $handle = fopen(CACHE_CSV, 'r');
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 4) {
            $cache[(string) $row[0]] = $row;
        }
    }
    fclose($handle);
    line('cached pages: ' . count($cache));
}

$titles = [];
foreach (CATEGORIES as $category) {
    $continue = '';
    do {
        $params = ['action' => 'query', 'list' => 'categorymembers',
                   'cmtitle' => $category, 'cmlimit' => 500];
        if ($continue !== '') {
            $params['cmcontinue'] = $continue;
        }
        $data = api($params);
        if ($data === null) {
            warn('category listing failed: ' . $category);
            break;
        }
        foreach ($data['query']['categorymembers'] ?? [] as $member) {
            $titles[(string) $member['title']] = true;
        }
        $continue = (string) ($data['continue']['cmcontinue'] ?? '');
        usleep(DELAY_US);
    } while ($continue !== '');
}
$titles = array_keys($titles);
line(count($titles) . ' set pages listed');

$results = [];
$scanned = 0;
$failed = [];

foreach ($titles as $title) {
    ++$scanned;

    if (isset($cache[$title])) {
        $results[$title] = $cache[$title];
        continue;
    }

    $data = api(['action' => 'parse', 'page' => $title, 'prop' => 'wikitext']);
    if ($data === null) {
        $failed[] = $title;
        continue;
    }
    $wikitext = (string) ($data['parse']['wikitext']['*'] ?? '');
    if ($wikitext === '') {
        $failed[] = $title;
        continue;
    }

    $french = field($wikitext, 'nom');
    $results[$title] = [
        $title,
        $french !== '' ? $french : $title,
        field($wikitext, 'nomen'),
        field($wikitext, 'série'),
    ];

    if ($scanned % 50 === 0) {
        echo "     ... $scanned / " . count($titles) . "\n";
    }
    usleep(DELAY_US);
}

$out = fopen(OUT, 'w');
fputcsv($out, ['page', 'name_fr', 'name_en', 'series_fr']);
foreach ($results as $row) {
    fputcsv($out, $row);
}
fclose($out);

$withEnglish = count(array_filter($results, static fn ($r) => trim((string) $r[2]) !== ''));
line(count($results) . ' pages captured, ' . $withEnglish . ' with an English name to match on');
if ($failed !== []) {
    warn(count($failed) . ' pages failed: ' . implode(', ', array_slice($failed, 0, 8))
        . (count($failed) > 8 ? ' ...' : ''));
}
line('written to ' . OUT);
