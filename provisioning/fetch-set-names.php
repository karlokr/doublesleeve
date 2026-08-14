<?php
/**
 * Collects official French names for the Western set catalogue.
 *
 * A Western set is ONE release printed in several languages - the same cards at
 * the same collector numbers - so it has an official name per language rather
 * than a translation we invent. "Surging Sparks" ships in France as "Étincelles
 * Déferlantes". (Japanese and Chinese sets are different releases entirely and are
 * not covered here; see the region model in docs/information-architecture.md.)
 *
 * Bulbapedia carries these in a {{Langtable}} on each set page, which is the only
 * free machine-readable source that covers the whole back catalogue. Results are
 * written to data/set-names-fr.csv and committed, so provisioning does no network
 * calls.
 *
 * Sets with no French entry keep their English name. SOME of those are genuinely
 * English-only - promo runs, trainer kits and box sets often were - but a miss and
 * an English-only release look identical from here, and the first cut of this
 * script reported plain lookup failures as "officially unchanged". "Crown Zenith"
 * is "Zénith Suprême"; "Shining Fates" is "Destinées Radieuses"; Bulbapedia files
 * "Scarlet & Violet 151" under the bare title "151". Treat a blank as unknown, not
 * as evidence.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/fetch-set-names.php
 *   ... --refresh         re-resolve every set
 *   ... --retry-missing   re-attempt only the sets that came back empty
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/era.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups.csv';
/**
 * Bulbapedia's answers only. resolve-set-names.php merges these with Poképédia and
 * the derivation rules into the final data/set-names-fr.csv.
 */
const NAMES_CSV = '/provisioning/data/set-names-bulbapedia.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const OUT = '/tmp/set-names-bulbapedia.csv';
const API = 'https://bulbapedia.bulbagarden.net/w/api.php';

define('REFRESH', in_array('--refresh', $argv ?? [], true));
/** Re-attempt only the sets that came back empty, keeping every name already found. */
define('RETRY_MISSING', in_array('--retry-missing', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== French names for Western sets\033[0m\n";

function api(array $params): array
{
    $context = stream_context_create(['http' => [
        'timeout' => 25,
        'user_agent' => 'DoubleSleeve/1.0 (set name localisation)',
    ]]);
    $body = @file_get_contents(API . '?' . http_build_query($params + ['format' => 'json']), false, $context);

    return $body === false ? [] : (json_decode($body, true) ?: []);
}

/** Pull `fr=` out of a page's {{Langtable}}, if it has one. */
function frenchFromPage(string $title): ?string
{
    $data = api(['action' => 'parse', 'page' => $title, 'prop' => 'wikitext']);
    $wikitext = $data['parse']['wikitext']['*'] ?? '';
    if ($wikitext === '') {
        return null;
    }

    /**
     * Capture to the Langtable's OWN closing braces, which sit alone on a line.
     *
     * A non-greedy match to the first "}}" stops at an inner {{tt|...}} template
     * - several tables open with a Chinese entry that uses one - truncating the
     * block before "fr=" is ever reached. Base Set and Evolving Skies both
     * silently returned "no French name" because of it.
     */
    if (!preg_match('/\{\{Langtable(.*?)\n\}\}/s', $wikitext, $table)) {
        return null;
    }
    if (!preg_match('/\|\s*fr\s*=\s*([^\n|}]+)/i', $table[1], $match)) {
        return null;
    }

    $french = trim($match[1]);

    /**
     * Strip a trailing wiki-template fragment.
     *
     * Some entries read `fr=Trésors Légendaires {{tt|...}}`, and the value capture
     * stops at the pipe INSIDE that template - leaving "Trésors Légendaires {{tt"
     * which PrestaShop then rejects as an invalid catalogue name.
     */
    $french = trim((string) preg_replace('/\s*\{\{.*$/s', '', $french));
    // Anything PrestaShop will not accept in a category name.
    $french = trim((string) preg_replace('/[<>;=#{}]/', '', $french));

    return $french === '' ? null : $french;
}

/**
 * Era prefixes Bulbapedia drops from its own page titles.
 *
 * TCGplayer writes the era into the group name; the wiki usually does not, so
 * "Scarlet & Violet 151" lives at "151" and "SM Base Set" at "Sun & Moon". Without
 * these the lookup simply fails and the set is recorded as having no French name.
 */
const TITLE_ERA_PREFIXES = [
    'Scarlet & Violet', 'Sword & Shield', 'Sun & Moon', 'Black & White',
    'Diamond & Pearl', 'HeartGold & SoulSilver', 'XY', 'SM', 'SWSH', 'SV', 'EX',
];

/**
 * Remainders that are NOT a set name once the era is stripped.
 *
 * "SV01: Scarlet & Violet Base Set" reduced to "Base Set", which resolves to the
 * 1999 WotC page - so three different modern sets all came back as "Set de Base",
 * colliding with each other AND with the real Base Set. These names only mean
 * anything with their era attached.
 */
const GENERIC_REMAINDERS = [
    'base set', 'base', 'promo cards', 'promos', 'promo', 'energies',
    'trainer kit', 'starter set', 'black star promos',
];

/** Bulbapedia page titles worth trying for a TCGplayer group name. */
function candidateTitles(string $groupName): array
{
    $base = trim(setDisplayName($groupName));          // "SV08: Surging Sparks" -> "Surging Sparks"
    $bare = trim(eraBaseName($groupName));
    $names = [$base, $bare, $groupName];

    // "Scarlet & Violet 151" -> "151"; also the spelled-out ampersand the wiki uses.
    foreach ([$base, $bare] as $name) {
        foreach (TITLE_ERA_PREFIXES as $prefix) {
            if (stripos($name, $prefix . ' ') !== 0) {
                continue;
            }
            $remainder = trim(substr($name, strlen($prefix)));
            if ($remainder !== '' && !in_array(strtolower($remainder), GENERIC_REMAINDERS, true)) {
                $names[] = $remainder;
            }
        }
        if (str_contains($name, '&')) {
            $names[] = str_replace('&', 'and', $name);
        }
    }

    $out = [];
    foreach (array_unique(array_filter(array_map('trim', $names))) as $name) {
        $out[] = $name . ' (TCG)';
        $out[] = $name;
    }

    return array_values(array_unique($out));
}

/**
 * Last resort: ask the wiki's own search where the set lives.
 *
 * Exact-title guessing cannot find "Expedition Base Set" from "Expedition" or
 * "Trick or Trade" from "Trick or Trade BOOster Bundle". Search can - but it will
 * also cheerfully return a related article, so a hit only counts when the title
 * actually covers the set's distinctive words.
 */
function searchTitles(string $groupName): array
{
    $name = trim(setDisplayName($groupName));
    $words = array_values(array_filter(
        preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [],
        static fn ($w) => strlen($w) > 2
            && !in_array($w, ['the', 'and', 'set', 'series', 'cards', 'card', 'pokemon'], true)
    ));
    if ($words === []) {
        return [];
    }

    $data = api([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => $name . ' TCG',
        'srnamespace' => 0,
        'srlimit' => 6,
    ]);

    $out = [];
    foreach (array_column($data['query']['search'] ?? [], 'title') as $title) {
        $haystack = strtolower($title);
        $hits = 0;
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                ++$hits;
            }
        }
        /**
         * EVERY distinctive word, no partial credit.
         *
         * Allowing one miss let "SWSH: Sword & Shield Promo Cards" match the era
         * page "Sword & Shield (TCG)" and come back as "Épée et Bouclier" - the era
         * name, confidently attached to a promo set. A missing name is recoverable;
         * a wrong one on a four-figure card page is not.
         */
        if ($hits === count($words)) {
            $out[] = $title;
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
$cache = [];
if (!REFRESH && is_readable(NAMES_CSV)) {
    $handle = fopen(NAMES_CSV, 'r');
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 3) {
            $cache[(int) $row[0]] = (string) $row[2];
        }
    }
    fclose($handle);
    line('cached entries: ' . count($cache));
}

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$groups = [];
while (($row = fgetcsv($handle)) !== false) {
    $g = array_combine($header, array_pad($row, count($header), ''));
    $groups[(int) $g['group_id']] = (string) $g['name'];
}
fclose($handle);

line(count($groups) . ' Western sets to resolve');

$results = [];
$found = 0;
$none = [];
$scanned = 0;

foreach ($groups as $groupId => $name) {
    ++$scanned;

    $cached = $cache[$groupId] ?? null;
    $keepCached = $cached !== null && ($cached !== '' || !RETRY_MISSING);
    if ($keepCached) {
        $results[$groupId] = [$name, $cached];
        if ($cached !== '') {
            ++$found;
        }
        continue;
    }

    $french = null;
    foreach (array_merge(candidateTitles($name), searchTitles($name)) as $title) {
        $french = frenchFromPage($title);
        if ($french !== null) {
            break;
        }
        usleep(200000);
    }

    $results[$groupId] = [$name, $french ?? ''];
    if ($french !== null) {
        ++$found;
        echo "     + $name  ->  $french\n";
    } else {
        $none[] = $name;
    }

    if ($scanned % 40 === 0) {
        echo "     ... $scanned scanned\n";
    }
}

/**
 * Backstop: a French name may belong to exactly one set.
 *
 * Two sets sharing one French name is always a resolver error, and it is the
 * expensive kind - "Black Bolt" and "White Flare" ship together, so a search hit on
 * their shared page gave BOTH of them "Foudre Noire". Whichever one is wrong is
 * wrong on the product title, the breadcrumb, the facet and the URL.
 *
 * There is no way to tell from here which claimant is right, so every claimant
 * loses the name and falls back to English. A missing translation is visible and
 * fixable; a confidently wrong one is neither.
 */
$claims = [];
foreach ($results as $groupId => [$english, $french]) {
    if ($french !== '') {
        $claims[$french][] = $groupId;
    }
}

$collisions = 0;
foreach ($claims as $french => $claimants) {
    if (count($claimants) < 2) {
        continue;
    }
    /**
     * Sets whose English names match once the parenthetical qualifier is removed
     * are genuinely the same release split by TCGplayer into print runs - "Base
     * Set" and "Base Set (Shadowless)" - and SHOULD share a name here. setLabel()
     * re-attaches and translates the qualifier afterwards, giving "Set de Base" and
     * "Set de Base (Sans ombre)". Comparing with the qualifier still attached made
     * the guard eat the one distinction on this site worth four figures.
     */
    $englishNames = array_unique(array_map(
        static fn ($id) => trim((string) preg_replace(
            '/\s*\([^)]*\)\s*$/', '', setDisplayName($results[$id][0])
        )),
        $claimants
    ));
    if (count($englishNames) < 2) {
        continue;
    }

    warn('"' . $french . '" claimed by ' . count($claimants) . ' sets - dropping it from all of them:');
    foreach ($claimants as $groupId) {
        warn('     ' . $results[$groupId][0]);
        $results[$groupId][1] = '';
        --$found;
        ++$collisions;
        $none[] = $results[$groupId][0];
    }
}
if ($collisions) {
    line("$collisions names dropped as ambiguous");
}

$out = fopen(OUT, 'w');
fputcsv($out, ['group_id', 'name_en', 'name_fr']);
foreach ($results as $groupId => [$en, $fr]) {
    fputcsv($out, [$groupId, $en, $fr]);
}
fclose($out);

line("French names found: $found / " . count($groups));
if ($none !== []) {
    line(count($none) . ' kept their English name (usually English-only releases)');
}
line('written to ' . OUT);
