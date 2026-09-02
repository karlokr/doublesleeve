<?php
/**
 * Backfills set logos that pokemontcg.io does not carry.
 *
 * pokemontcg.io catalogues 174 sets against TCGplayer's 217, and it lags new
 * releases - "30th Celebration" is a real September 2026 expansion with an
 * official logo that simply is not in that API yet. The Bulbagarden Archives has
 * essentially all of them, and exposes a MediaWiki API, so the gap is closable.
 *
 * Results are written to data/set-logos-extra.csv and committed. The API is only
 * consulted for sets not already in that file, so a normal provisioning run does
 * no network calls at all and the mapping is reproducible.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/catalog/fetch-set-logos.php
 *   ... --refresh   re-resolve every set, ignoring the cache
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/logo.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const SERIES_CSV = '/provisioning/data/pokemon-sets.csv';
const EXTRA_CSV = '/provisioning/data/set-logos-extra.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const EXTRA_OUT = '/tmp/set-logos-extra.csv';
const API = 'https://archives.bulbagarden.net/w/api.php';

define('REFRESH', in_array('--refresh', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Backfill set logos from Bulbagarden Archives\033[0m\n";

function apiGet(array $params): array
{
    $url = API . '?' . http_build_query($params + ['format' => 'json']);
    $context = stream_context_create(['http' => [
        'timeout' => 25,
        'user_agent' => 'DoubleSleeve/1.0 (storefront set-logo backfill)',
    ]]);
    $body = @file_get_contents($url, false, $context);

    return $body === false ? [] : (json_decode($body, true) ?: []);
}

/**
 * Words that must survive matching. Dropping stopwords and the era code stops
 * "SM Trainer Kit: Lycanroc & Alolan Raichu" from matching any old trainer kit.
 */
function significantWords(string $name): array
{
    $stop = ['the', 'and', 'of', 'a', 'set', 'series', 'cards', 'card', 'pokemon', 'pokémon'];
    $words = preg_split('/[^a-z0-9]+/', strtolower(eraBaseName($name))) ?: [];

    return array_values(array_filter(
        $words,
        static fn ($w) => strlen($w) > 2 && !in_array($w, $stop, true)
    ));
}

/**
 * How well a candidate title covers the set name, 0.0-1.0, or -1 to reject.
 *
 * Requiring EVERY word matched rejected almost everything real ("McDonald's
 * Promos 2011" is filed as "McDonald's Collection 2011"). Requiring none would
 * happily return a plausible but wrong logo, which is worse than no logo at all.
 * The compromise: the single most distinctive word must appear, and enough of the
 * rest to be confident it is the same set.
 */
function titleScore(string $title, array $words): float
{
    if ($words === []) {
        return -1.0;
    }
    $haystack = strtolower($title);

    // Longest word carries the identity - "Lycanroc", "Excadrill", "Celebration".
    $anchor = $words[0];
    foreach ($words as $word) {
        if (strlen($word) > strlen($anchor)) {
            $anchor = $word;
        }
    }
    if (!str_contains($haystack, $anchor)) {
        return -1.0;
    }

    $hits = 0;
    foreach ($words as $word) {
        if (str_contains($haystack, $word)) {
            ++$hits;
        }
    }
    $coverage = $hits / count($words);

    return $coverage >= 0.5 ? $coverage : -1.0;
}

function findLogoUrl(string $setName): ?string
{
    $words = significantWords($setName);
    if ($words === []) {
        return null;
    }

    $search = apiGet([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => eraBaseName($setName) . ' logo',
        'srnamespace' => 6,
        'srlimit' => 20,
    ]);

    $titles = array_column($search['query']['search'] ?? [], 'title');
    if ($titles === []) {
        return null;
    }

    // Prefer the English logo; the archive holds JP and other locales too.
    $ranked = [];
    foreach ($titles as $title) {
        $coverage = titleScore($title, $words);
        if ($coverage < 0) {
            continue;
        }
        if (preg_match('/\b(JP|JPN|Japanese|KO|TW|CN|ZH)\b/i', $title)) {
            continue;
        }
        $score = $coverage * 10;
        if (stripos($title, 'logo') !== false) { $score += 10; }
        if (preg_match('/\bEN\b/i', $title)) { $score += 5; }
        if (preg_match('/\.(png|jpg|jpeg)$/i', $title)) { $score += 1; }
        $ranked[$title] = $score;
    }
    if ($ranked === []) {
        return null;
    }
    arsort($ranked);

    $info = apiGet([
        'action' => 'query',
        'titles' => array_key_first($ranked),
        'prop' => 'imageinfo',
        'iiprop' => 'url|size',
    ]);

    foreach ($info['query']['pages'] ?? [] as $page) {
        foreach ($page['imageinfo'] ?? [] as $ii) {
            // Guard against thumbnails and stray icons being treated as logos.
            if ((int) ($ii['width'] ?? 0) >= 200) {
                return (string) $ii['url'];
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
$cache = [];
if (!REFRESH && is_readable(EXTRA_CSV)) {
    $handle = fopen(EXTRA_CSV, 'r');
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 2) {
            $cache[(int) $row[0]] = (string) $row[2];
        }
    }
    fclose($handle);
    line('cached entries: ' . count($cache));
}

$seriesMap = loadSeriesMap(SERIES_CSV);
$logoIndex = loadLogoIndex(SERIES_CSV);

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$needed = [];
while (($row = fgetcsv($handle)) !== false) {
    $g = array_combine($header, array_pad($row, count($header), ''));
    $name = (string) $g['name'];
    $groupId = (int) $g['group_id'];
    if (trim((string) $g['logo_url']) !== '') {
        continue;
    }
    $era = resolveEra($name, (string) $g['published_on'], $seriesMap);
    if (resolveLogo($name, $era, $logoIndex) !== null) {
        continue;
    }
    $needed[$groupId] = $name;
}
fclose($handle);

line(count($needed) . ' sets have no logo from pokemontcg.io');

$found = 0;
$missing = [];
$results = [];

foreach ($needed as $groupId => $name) {
    if (isset($cache[$groupId]) && $cache[$groupId] !== '') {
        $results[$groupId] = [$name, $cache[$groupId]];
        ++$found;
        continue;
    }

    $url = findLogoUrl($name);
    if ($url !== null) {
        $results[$groupId] = [$name, $url];
        ++$found;
        echo "     + $name\n";
    } else {
        $missing[] = $name;
    }
    usleep(350000); // be a good citizen against a community wiki
}

$out = fopen(EXTRA_OUT, 'w');
fputcsv($out, ['group_id', 'name', 'logo_url']);
foreach ($results as $groupId => [$name, $url]) {
    fputcsv($out, [$groupId, $name, $url]);
}
fclose($out);

line("resolved: $found / " . count($needed) . ' (written to ' . EXTRA_OUT . ')');
if ($missing !== []) {
    warn(count($missing) . ' still unresolved: ' . implode(', ', array_slice($missing, 0, 12))
        . (count($missing) > 12 ? ' ...' : ''));
}
