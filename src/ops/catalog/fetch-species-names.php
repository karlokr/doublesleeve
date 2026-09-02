<?php
/**
 * Adds translated species names to data/pokemon-species.csv.
 *
 * The "Pokemon" facet lists SPECIES names, which is a search aid rather than the
 * text printed on a card - and species names are officially localised
 * (Charizard / Dracaufeu / Glurak). Leaving them English made the French facet
 * unusable for anyone who knows the cards by their French names.
 *
 * PokeAPI is the only free source that carries every localisation, and it has no
 * bulk names endpoint, so this walks 1,025 species once and commits the result.
 * Provisioning then reads the CSV and makes no network calls at all.
 *
 * Species names are the STOREFRONT language, not the card language - the facet is
 * a search aid, not the text printed on the card.
 *
 * Resumable: already-populated rows are skipped, so an interrupted run costs
 * nothing to restart.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/catalog/fetch-species-names.php
 *   ... --refresh   re-fetch every species
 */
declare(strict_types=1);

const SPECIES_CSV = '/provisioning/data/pokemon-species.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const OUT = '/tmp/pokemon-species.csv';
const API = 'https://pokeapi.co/api/v2/pokemon-species/';

/**
 * PokeAPI language code => our CSV column.
 *
 * Only the storefront languages. Carrying German or Spanish species names for a
 * site that does not run in those languages is 3,000 rows of data nobody reads -
 * and the CARD language is a separate axis entirely (see the region model in
 * docs/information-architecture.md), so it is not served by this either.
 */
const WANTED = ['fr' => 'name_fr'];

define('REFRESH', in_array('--refresh', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Species name localisation\033[0m\n";

if (!is_readable(SPECIES_CSV)) {
    warn('species CSV not found: ' . SPECIES_CSV);
    exit(1);
}

$handle = fopen(SPECIES_CSV, 'r');
$header = fgetcsv($handle);
$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    if ($row === [null] || $row === []) {
        continue;
    }
    $rows[] = array_combine($header, array_pad($row, count($header), ''));
}
fclose($handle);

line(count($rows) . ' species in CSV');

$columns = array_values(array_unique(array_merge($header, array_values(WANTED))));

function fetchNames(int $dex): array
{
    $context = stream_context_create(['http' => [
        'timeout' => 20,
        'user_agent' => 'DoubleSleeve/1.0 (species localisation)',
    ]]);

    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $body = @file_get_contents(API . $dex, false, $context);
        if ($body !== false) {
            $data = json_decode($body, true);
            $out = [];
            foreach ($data['names'] ?? [] as $entry) {
                $code = (string) ($entry['language']['name'] ?? '');
                if (isset(WANTED[$code])) {
                    $out[WANTED[$code]] = (string) $entry['name'];
                }
            }

            return $out;
        }
        sleep($attempt);
    }

    return [];
}

$fetched = 0;
$skipped = 0;
$failed = [];

foreach ($rows as $index => $row) {
    $dex = (int) ($row['dex_number'] ?? 0);
    if ($dex <= 0) {
        continue;
    }

    $hasAll = true;
    foreach (WANTED as $column) {
        if (trim((string) ($row[$column] ?? '')) === '') {
            $hasAll = false;
            break;
        }
    }
    if ($hasAll && !REFRESH) {
        ++$skipped;
        continue;
    }

    $names = fetchNames($dex);
    if ($names === []) {
        $failed[] = $row['name'] ?? ('#' . $dex);
        continue;
    }

    foreach ($names as $column => $value) {
        $rows[$index][$column] = $value;
    }
    ++$fetched;

    if ($fetched % 100 === 0) {
        echo "     ... $fetched fetched\n";
    }
    usleep(120000);   // be a good citizen against a free API
}

$out = fopen(OUT, 'w');
fputcsv($out, $columns);
foreach ($rows as $row) {
    $ordered = [];
    foreach ($columns as $column) {
        $ordered[] = $row[$column] ?? '';
    }
    fputcsv($out, $ordered);
}
fclose($out);

line("fetched: $fetched, already had names: $skipped");
if ($failed !== []) {
    warn(count($failed) . ' failed: ' . implode(', ', array_slice($failed, 0, 10)));
}
line('written to ' . OUT);
