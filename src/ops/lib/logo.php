<?php
/**
 * Resolves a TCGplayer group to a pokemontcg.io set logo.
 *
 * The two catalogues name the same set differently in a dozen systematic ways, so
 * matching is a list of candidate spellings tried against an era-scoped index.
 * Era scoping matters: "XY Base Set" must not match the 1999 "Base Set".
 */
declare(strict_types=1);

require_once __DIR__ . '/era.php';

/** @return array{byEra: array<string,array<string,string>>, global: array<string,string>} */
function loadLogoIndex(string $setsCsv): array
{
    $series = loadSeriesMap($setsCsv);
    $byEra = [];
    $global = [];

    $handle = fopen($setsCsv, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $r = array_combine($header, array_pad($row, count($header), ''));
        $logo = trim((string) $r['logo_url']);
        if ($logo === '') {
            continue;
        }
        $era = resolveEra((string) $r['set_name'], (string) $r['release_date'], $series);
        foreach ([$r['set_name'], eraBaseName((string) $r['set_name'])] as $variant) {
            $key = eraKey((string) $variant);
            if ($key === '') {
                continue;
            }
            $byEra[$era][$key] ??= $logo;
            $global[$key] ??= $logo;
        }
    }
    fclose($handle);

    return ['byEra' => $byEra, 'global' => $global];
}

/**
 * Every spelling of $name worth trying, most specific first.
 *
 * @return array<int,string>
 */
function logoCandidates(string $name, string $era): array
{
    $codes = implode('|', array_keys(ERA_PREFIXES));
    $out = [$name, eraBaseName($name)];

    // "EX Ruby and Sapphire" -> "Ruby & Sapphire": pokemontcg.io drops the era code.
    $bare = preg_replace('/^(' . $codes . ')[0-9]*\s+/i', '', $name) ?? $name;
    $out[] = $bare;

    foreach ([$name, eraBaseName($name), $bare] as $variant) {
        // "SWSH01: Sword & Shield Base Set" -> "Sword & Shield"
        $out[] = preg_replace('/\s+base\s+set$/i', '', (string) $variant) ?? '';
        // "Base Set" -> "Base": pokemontcg.io names the 1999 set without "Set".
        $out[] = preg_replace('/\s+set$/i', '', (string) $variant) ?? '';
        // "Expedition" -> "Expedition Base Set"
        $out[] = $variant . ' Base Set';
        // "XY Promos" -> "XY Black Star Promos"
        $out[] = preg_replace('/\s+promos?$/i', ' Black Star Promos', (string) $variant) ?? '';
        // "SV: Scarlet & Violet 151" -> "151"
        $out[] = preg_replace('/^' . preg_quote($era, '/') . '\s+/i', '', (string) $variant) ?? '';
    }

    // "SM Base Set" carries no set name at all - the era IS the set on pokemontcg.io.
    if (preg_match('/\bbase\s+set$/i', $name)) {
        $out[] = $era;
    }
    // Sub-collections inherit their parent set's logo.
    if (str_contains($name, ':')) {
        $out[] = trim(explode(':', $name, 2)[0]);
    }

    return array_values(array_filter(array_unique($out), static fn ($v) => trim((string) $v) !== ''));
}

/** Hand-mapped names no rule generalises to. */
const LOGO_ALIASES = [
    'wotcpromo' => 'Wizards Black Star Promos',
    'rumble' => 'Pokemon Rumble',
    'expedition' => 'Expedition Base Set',
    'blackandwhitepromos' => 'BW Black Star Promos',
    'diamondandpearlpromos' => 'DP Black Star Promos',
];

function resolveLogo(string $name, string $era, array $index): ?string
{
    $alias = LOGO_ALIASES[eraKey($name)] ?? null;
    $candidates = $alias !== null
        ? array_merge([$alias], logoCandidates($name, $era))
        : logoCandidates($name, $era);

    // Era-scoped first, so a generic name lands in the right generation.
    foreach ($candidates as $candidate) {
        $key = eraKey($candidate);
        if ($key !== '' && isset($index['byEra'][$era][$key])) {
            return $index['byEra'][$era][$key];
        }
    }
    foreach ($candidates as $candidate) {
        $key = eraKey($candidate);
        if ($key !== '' && isset($index['global'][$key])) {
            return $index['global'][$key];
        }
    }

    return null;
}
