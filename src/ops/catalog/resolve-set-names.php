<?php
/**
 * Resolves a French name for EVERY set, from the sources in order of authority.
 *
 * The requirement is total coverage: no set may fall back to English, because a
 * French storefront that names half its catalogue in English is not a French
 * storefront. Nothing here touches the network - both wiki fetchers commit their
 * answers to CSV first.
 *
 *   1. Poképédia   - the French wiki, so every set is already filed under its
 *                    French name and each page states the English one
 *   2. Bulbapedia  - the {{Langtable}} on multi-language releases
 *   3. Derivation  - rules + a curated list, for product that was never printed in
 *                    French at all (McDonald's runs, trainer kits, blisters)
 *
 * Provenance is recorded per row, so "official" and "we composed this" never get
 * confused later.
 *
 *   make set-names
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/../lib/era.php';
require_once __DIR__ . '/../lib/cardname.php';
require_once __DIR__ . '/../lib/setname-fr.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const POKEPEDIA_CSV = '/provisioning/data/pokepedia-sets.csv';
const BULBAPEDIA_CSV = '/provisioning/data/set-names-bulbapedia.csv';
const SPECIES_CSV = '/provisioning/data/pokemon-species.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const OUT = '/tmp/set-names-fr.csv';

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Resolving a French name for every set\033[0m\n";

/**
 * Comparison key for an English set name.
 *
 * The two wikis and TCGplayer punctuate differently - "Pokemon GO" / "Pokémon GO",
 * "Black and White" / "Black & White", "Champion's Path" / "Champions Path" - and
 * every one of those mismatches reads as "this set has no French name".
 */
function nameKey(string $name): string
{
    $name = str_replace(['&', 'é', 'É', 'è', 'ê', 'à', 'ô', 'û', 'î', 'ç'],
                        ['and', 'e', 'e', 'e', 'e', 'a', 'o', 'u', 'i', 'c'], $name);
    $name = strtolower($name);

    return trim((string) preg_replace(['/[^a-z0-9]+/', '/\s+/'], [' ', ' '], $name));
}

// ---------------------------------------------------------------------------
// sources
// ---------------------------------------------------------------------------
function loadCsv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }
        $rows[] = array_combine($header, array_pad($row, count($header), ''));
    }
    fclose($handle);

    return $rows;
}

$pokepedia = [];
foreach (loadCsv(POKEPEDIA_CSV) as $row) {
    $english = trim((string) ($row['name_en'] ?? ''));
    $french = trim((string) ($row['name_fr'] ?? ''));
    if ($english !== '' && $french !== '') {
        $pokepedia[nameKey($english)] = $french;
    }
}
line(count($pokepedia) . ' Poképédia mappings');

$bulbapedia = [];
foreach (loadCsv(BULBAPEDIA_CSV) as $row) {
    $english = trim((string) ($row['name_en'] ?? ''));
    $french = trim((string) ($row['name_fr'] ?? ''));
    if ($english !== '' && $french !== '') {
        $bulbapedia[nameKey($english)] = $french;
        $bulbapedia[nameKey(setDisplayName($english))] = $french;
    }
}
line(count($bulbapedia) . ' Bulbapedia mappings');

/**
 * Poképédia writes the era into the set name - "Épée et Bouclier Stars
 * Étincelantes" - because that is how the French box is branded. Our English names
 * deliberately drop the release code, so the French must drop the era to match, or
 * the two storefronts disagree about what a set is called.
 *
 * Only where the ENGLISH name drops it too: "EX Dragon" and "EX Rubis & Saphir"
 * both keep their prefix on purpose, because there the prefix IS the name.
 */
function stripEraPrefix(string $french, string $englishDisplay): string
{
    foreach (ERA_FR as $englishEra => $frenchEra) {
        if (stripos($englishDisplay, $englishEra . ' ') === 0) {
            continue;   // English keeps it, so French keeps it
        }
        foreach ([$frenchEra, $englishEra] as $prefix) {
            if (stripos($french, $prefix . ' ') === 0) {
                $stripped = trim(substr($french, strlen($prefix)));
                // Never strip a name down to nothing - "XY" alone is a real set.
                if ($stripped !== '') {
                    return $stripped;
                }
            }
        }
    }

    return $french;
}

/** Species names, so trainer-kit titles name the Pokémon a French buyer knows. */
$speciesVocab = nameVocabulary(SPECIES_CSV, 'name_fr');
line(count($speciesVocab['species']) . ' species names for embedded card names');

// ---------------------------------------------------------------------------
// resolution
// ---------------------------------------------------------------------------
/**
 * French for one name fragment - a whole set, an era, or the pair of Pokémon in a
 * trainer kit. Used recursively by the derivation rules so a composed name reuses
 * official parts instead of re-inventing them.
 */
$resolve = function (string $english) use (&$resolve, $pokepedia, $bulbapedia, $speciesVocab): string {
    $english = trim($english);
    if ($english === '') {
        return '';
    }

    $override = overrideFrenchSetName($english) ?? overrideFrenchSetName(setDisplayName($english));
    if ($override !== null) {
        return $override;
    }

    foreach ([$english, setDisplayName($english)] as $candidate) {
        $key = nameKey($candidate);
        if (isset($pokepedia[$key])) {
            return stripEraPrefix($pokepedia[$key], setDisplayName($english));
        }
        if (isset($bulbapedia[$key])) {
            return $bulbapedia[$key];
        }
    }

    if (isset(ERA_FR[$english])) {
        return ERA_FR[$english];
    }

    $derived = deriveFrenchSetName($english, $resolve);
    if ($derived !== null) {
        return $derived;
    }

    /**
     * A bare fragment - typically the two Pokémon in a trainer kit.
     *
     * Split on the conjunction first: localiseCardName translates ONE species per
     * string and returns, so "Lycanroc et Alolan Raichu" came back half-French.
     */
    return implode(' et ', array_map(
        static fn ($part) => localiseCardName(trim($part), $speciesVocab),
        explode(' et ', $english)
    ));
};

$groups = loadCsv(GROUPS_CSV);
line(count($groups) . ' sets to resolve');

$results = [];
$counts = ['override' => 0, 'pokepedia' => 0, 'bulbapedia' => 0, 'derived' => 0, 'unresolved' => 0];
$unresolved = [];

foreach ($groups as $group) {
    $groupId = (int) $group['group_id'];
    $english = trim((string) $group['name']);
    $display = setDisplayName($english);

    $french = '';
    $source = '';

    // Settled by hand where the wikis contradict each other - see setname-fr.php.
    $override = overrideFrenchSetName($display) ?? overrideFrenchSetName($english);
    if ($override !== null) {
        $french = $override;
        $source = 'override';
    }

    foreach ($french === '' ? [$english, $display] : [] as $candidate) {
        $key = nameKey($candidate);
        if (isset($pokepedia[$key])) {
            $french = stripEraPrefix($pokepedia[$key], $display);
            $source = 'pokepedia';
            break;
        }
    }
    if ($french === '') {
        foreach ([$english, $display] as $candidate) {
            $key = nameKey($candidate);
            if (isset($bulbapedia[$key])) {
                $french = $bulbapedia[$key];
                $source = 'bulbapedia';
                break;
            }
        }
    }
    if ($french === '') {
        $derived = deriveFrenchSetName($display, $resolve);
        if ($derived !== null && trim($derived) !== '') {
            $french = $derived;
            $source = 'derived';
        }
    }

    if ($french === '') {
        $unresolved[] = $display;
        ++$counts['unresolved'];
        // Never write a blank: an English name is a worse answer than a composed
        // French one, but it is still an answer, and the run must not lie about
        // coverage by leaving the column empty.
        $french = $display;
        $source = 'english';
    } else {
        ++$counts[$source];
    }

    $results[$groupId] = [$groupId, $english, $french, $source];
}

/**
 * The same backstop as the Bulbapedia fetcher: one French name, one set.
 *
 * A collision here is a resolution bug, not a coincidence - two sets sharing a name
 * means the storefront, the breadcrumb and the facet all become ambiguous. Print-run
 * pairs are exempt; they are the same release and setLabel() re-attaches the
 * qualifier.
 */
$claims = [];
foreach ($results as $groupId => [, $english, $french]) {
    $claims[$french][] = $groupId;
}
$collisions = 0;
foreach ($claims as $french => $claimants) {
    if (count($claimants) < 2) {
        continue;
    }
    $bare = array_unique(array_map(
        static fn ($id) => nameKey((string) preg_replace('/\s*\([^)]*\)\s*$/', '', setDisplayName($results[$id][1]))),
        $claimants
    ));
    if (count($bare) < 2) {
        continue;
    }

    warn('"' . $french . '" claimed by ' . count($claimants) . ' sets:');
    foreach ($claimants as $groupId) {
        // Disambiguate rather than blank it - total coverage is the requirement, so
        // the era is appended to make each name unique instead of dropping it.
        $era = resolveEra($results[$groupId][1], '', []);
        $suffix = $era !== '' && !str_contains($results[$groupId][2], eraFrench($era))
            ? ' (' . eraFrench($era) . ')'
            : ' (' . setCode($results[$groupId][1]) . ')';
        $results[$groupId][2] = trim($results[$groupId][2] . $suffix);
        $results[$groupId][3] = 'disambiguated';
        warn('     ' . $results[$groupId][1] . '  ->  ' . $results[$groupId][2]);
        ++$collisions;
    }
}

$out = fopen(OUT, 'w');
fputcsv($out, ['group_id', 'name_en', 'name_fr', 'source']);
foreach ($results as $row) {
    fputcsv($out, $row);
}
fclose($out);

echo "\n";
line('hand-settled: ' . $counts['override']);
line('Poképédia: ' . $counts['pokepedia']);
line('Bulbapedia: ' . $counts['bulbapedia']);
line('derived: ' . $counts['derived']);
if ($collisions) {
    line("disambiguated: $collisions");
}
if ($unresolved !== []) {
    warn(count($unresolved) . ' UNRESOLVED, left in English: ' . implode(', ', $unresolved));
} else {
    line('every set has a French name');
}
line('written to ' . OUT);
