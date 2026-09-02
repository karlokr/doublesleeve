<?php
/**
 * Finds TCGplayer groups that are parallel print runs of the same cards.
 *
 * Two shapes look identical from the group list but must be modelled oppositely:
 *
 *   PARALLEL PRINT RUN - the same cards printed again. "Base Set" and "Base Set
 *   (Shadowless)" share 100 of ~109 card names. A buyer choosing between them is
 *   choosing a print run, and the price gap is enormous, so they must stay
 *   separate sets AND be labelled unmistakably.
 *
 *   SUBSET - extra cards that shipped alongside a set. "Hidden Fates: Shiny
 *   Vault" is different cards with their own numbering. Separate set, no print-run
 *   labelling needed.
 *
 * Card-name overlap separates them: high overlap means a reprint, near-zero means
 * a subset. Guessing was how the shadowless distinction nearly got missed.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/audits/audit-parallel-sets.php
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups.csv';
const TCGCSV_BASE = 'https://tcgcsv.com/tcgplayer/3/';
/** Above this share of shared card names, a pair is a reprint, not a subset. */
const PARALLEL_THRESHOLD = 50;

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Parallel print-run audit\033[0m\n";

/**
 * Card identities in a group: "name|number".
 *
 * Name alone is not enough. Base Set 2 reprinted 86% of Base Set's card NAMES,
 * but renumbered everything (Alakazam is 1/102 in Base Set and 1/130 in Base Set
 * 2) - it is a new release, not a new print run of the old one. A genuine
 * parallel print run keeps the collector number identical, which is exactly what
 * makes Base Set (Shadowless) the thing a buyer must be able to tell apart.
 */
function groupCardNames(int $groupId): array
{
    static $cache = [];
    if (isset($cache[$groupId])) {
        return $cache[$groupId];
    }
    $context = stream_context_create(['http' => [
        'timeout' => 45,
        'user_agent' => 'DoubleSleeve/1.0 (catalogue audit)',
    ]]);
    $body = @file_get_contents(TCGCSV_BASE . $groupId . '/products', false, $context);
    if ($body === false) {
        return $cache[$groupId] = [];
    }
    $data = json_decode($body, true);
    $names = [];
    foreach ($data['results'] ?? [] as $product) {
        $name = strtolower(trim((string) ($product['name'] ?? '')));
        $name = trim((string) preg_replace('/\s*\(\d+\)$/', '', $name));
        if ($name === '') {
            continue;
        }
        $number = '';
        foreach ($product['extendedData'] ?? [] as $field) {
            if (strcasecmp((string) ($field['name'] ?? ''), 'Number') === 0) {
                $number = strtolower(trim((string) ($field['value'] ?? '')));
                break;
            }
        }
        $names[$name . '|' . $number] = true;
    }

    return $cache[$groupId] = $names;
}

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$groups = [];
while (($row = fgetcsv($handle)) !== false) {
    $g = array_combine($header, array_pad($row, count($header), ''));
    $groups[(string) $g['name']] = (int) $g['group_id'];
}
fclose($handle);

// Candidates: any group whose name extends another group's name, plus the
// parenthetical form which is how TCGplayer writes a pure reprint.
$pairs = [];
foreach ($groups as $child => $childId) {
    foreach ($groups as $parent => $parentId) {
        if ($child === $parent) {
            continue;
        }
        if (str_starts_with($child, $parent . ' ') || str_starts_with($child, $parent . ':')) {
            $pairs[] = [$parent, $parentId, $child, $childId];
        }
    }
}
line(count($pairs) . ' candidate pairs from name shape');

$parallel = [];
printf("\n   %-38s %-44s %5s %5s %6s %8s  %s\n", 'PARENT', 'CHILD', 'A', 'B', 'SHARED', 'OVERLAP', 'VERDICT');

foreach ($pairs as [$parentName, $parentId, $childName, $childId]) {
    $a = groupCardNames($parentId);
    $b = groupCardNames($childId);
    if ($a === [] || $b === []) {
        printf("   %-38s %-44s  fetch failed\n", substr($parentName, 0, 38), substr($childName, 0, 44));
        continue;
    }
    $shared = count(array_intersect_key($a, $b));
    $overlap = (int) round(100 * $shared / max(1, min(count($a), count($b))));
    $verdict = $overlap >= PARALLEL_THRESHOLD ? 'PARALLEL PRINT RUN' : 'subset';
    if ($overlap >= PARALLEL_THRESHOLD) {
        $parallel[] = [$parentName, $childName, $overlap];
    }

    printf("   %-38s %-44s %5d %5d %6d %7d%%  %s\n",
        substr($parentName, 0, 38), substr($childName, 0, 44),
        count($a), count($b), $shared, $overlap, $verdict);
    usleep(150000);
}

echo "\n";
if ($parallel === []) {
    line('no parallel print runs beyond those already modelled');
} else {
    line(count($parallel) . ' parallel print run(s) found - each needs explicit labelling:');
    foreach ($parallel as [$p, $c, $o]) {
        line("   $p  <->  $c   ({$o}% shared cards)");
    }
}
