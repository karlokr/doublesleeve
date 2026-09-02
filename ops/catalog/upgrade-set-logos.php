<?php
/**
 * Replaces low-resolution set logos with better ones from the Bulbagarden Archives.
 *
 * pokemontcg.io's logos are small - Legendary Collection ships at 198x74, as an
 * indexed PNG with one-bit transparency - so the set tiles were scaling a
 * thumbnail up in the browser and showing every stair-step in it. No amount of
 * processing fixes that; the source has to be bigger. The Archives host the
 * originals, routinely 600-1500px wide.
 *
 * Runs over BOTH regions: the Western sets whose current art is small, and any
 * Japanese set whose logo is likewise thin. Only an image that is actually
 * WIDER than what is on disk is accepted, so a run can never make a tile worse.
 *
 * Results are cached in data/set-logos-hires.csv and committed; seed-category-
 * images.php prefers that file over every other source.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/catalog/upgrade-set-logos.php
 *   ... --min=600   treat anything narrower than this as needing an upgrade
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const CACHE_CSV = '/provisioning/data/set-logos-hires.csv';
const CACHE_OUT = '/tmp/set-logos-hires.csv';
const API = 'https://archives.bulbagarden.net/w/api.php';
const DELAY_US = 350000;

$minWidth = 520;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $minWidth = max(64, (int) substr($arg, 6));
    }
}

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Upgrading low-resolution set logos\033[0m\n";
line("anything narrower than {$minWidth}px is a candidate");

function apiGet(array $params): array
{
    $url = API . '?' . http_build_query($params + ['format' => 'json']);
    $context = stream_context_create(['http' => [
        'timeout' => 25, 'user_agent' => 'DoubleSleeve/1.0 (storefront set-logo upgrade)',
    ]]);
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $body = @file_get_contents($url, false, $context);
        if ($body !== false && is_array($decoded = json_decode($body, true))) {
            return $decoded;
        }
        sleep($attempt);
    }

    return [];
}

/**
 * Words that carry a set's identity, for verifying a candidate really is it.
 *
 * @return array<int, string>
 */
function identityWords(string $name): array
{
    $stop = ['the', 'and', 'of', 'a', 'set', 'series', 'cards', 'card', 'pokemon', 'pokémon', 'tcg'];
    $words = preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [];

    return array_values(array_filter(
        $words,
        static fn ($w) => strlen($w) > 2 && !in_array($w, $stop, true)
    ));
}

/**
 * The widest logo file that is ACTUALLY this set, with its width.
 *
 * Width alone was the rule and it produced confident nonsense: "Fossil" matched
 * "Pokémon Fossil Museum" (a 2023 exhibition), "Gym Challenge" matched the "Gym
 * Leader Challenge" fan format. Both contain the word and both are wider, so
 * both won. A candidate now has to LOOK LIKE the set: every identity word of the
 * set must appear in the file title, and the title must not introduce a strong
 * extra word of its own - that is what separates "Fossil Logo" from "Fossil
 * Museum Logo".
 *
 * @return array{url: string, width: int}|null
 */
function bestLogo(string $name): ?array
{
    $wanted = identityWords($name);
    if ($wanted === []) {
        return null;
    }

    $data = apiGet([
        'action' => 'query', 'list' => 'search',
        'srsearch' => $name . ' logo', 'srnamespace' => 6, 'srlimit' => 12,
    ]);

    $titles = [];
    foreach ($data['query']['search'] ?? [] as $hit) {
        $title = (string) ($hit['title'] ?? '');
        if (!preg_match('/logo/i', $title)) {
            continue;
        }
        // Localised reprints are different artwork under the same set's name.
        if (preg_match('/indonesian|hindi|thai|chinese|korean|vietnam|brazil|russia|german|french|italian|spanish/i', $title)) {
            continue;
        }

        $titleWords = identityWords(preg_replace('/^File:|\.[a-z0-9]+$|logo/i', ' ', $title));
        // Every word of the set name must be present...
        foreach ($wanted as $word) {
            if (!in_array($word, $titleWords, true)) {
                continue 2;
            }
        }
        // ...and the title must not carry a distinctive word the set does not,
        // which is what "Museum" and "Leader" were.
        foreach ($titleWords as $word) {
            if (!in_array($word, $wanted, true) && strlen($word) > 3) {
                continue 2;
            }
        }
        $titles[] = $title;
    }
    if ($titles === []) {
        return null;
    }

    $info = apiGet([
        'action' => 'query', 'titles' => implode('|', array_slice($titles, 0, 20)),
        'prop' => 'imageinfo', 'iiprop' => 'url|size',
    ]);
    $best = null;
    foreach ($info['query']['pages'] ?? [] as $page) {
        $ii = $page['imageinfo'][0] ?? null;
        if (!$ii || empty($ii['url']) || empty($ii['width'])) {
            continue;
        }
        if ($best === null || (int) $ii['width'] > $best['width']) {
            $best = ['url' => (string) $ii['url'], 'width' => (int) $ii['width']];
        }
    }

    return $best;
}

// ---------------------------------------------------------------------------
$cached = [];
if (is_readable(CACHE_CSV)) {
    $fh = fopen(CACHE_CSV, 'r');
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) >= 3 && trim((string) $r[2]) !== '') {
            $cached[(int) $r[0]] = (string) $r[2];
        }
    }
    fclose($fh);
    line(count($cached) . ' upgrades already cached');
}

/** Every set category that currently has an image, with the group it maps to. */
$sets = Db::getInstance()->executeS(
    'SELECT g.group_id, g.id_category, cl.name
       FROM ' . _DB_PREFIX_ . 'tcg_group_category g
       JOIN ' . _DB_PREFIX_ . 'category_lang cl
            ON cl.id_category = g.id_category AND cl.id_lang = 1 AND cl.id_shop = 1'
) ?: [];

$checked = 0;
$upgraded = 0;
$resolved = $cached;

foreach ($sets as $set) {
    $groupId = (int) $set['group_id'];
    if (isset($resolved[$groupId])) {
        continue;
    }
    $path = _PS_CAT_IMG_DIR_ . (int) $set['id_category'] . '.jpg';
    if (!is_file($path)) {
        continue;
    }
    $size = @getimagesize($path);
    if (!$size || (int) $size[0] >= $minWidth) {
        continue;
    }
    ++$checked;

    // The display name has no release code, which is what the wiki indexes on.
    $best = bestLogo((string) $set['name']);
    usleep(DELAY_US);
    if ($best === null || $best['width'] <= (int) $size[0]) {
        continue;
    }

    $resolved[$groupId] = $best['url'];
    ++$upgraded;
    line(sprintf('%-38s %dpx -> %dpx', substr((string) $set['name'], 0, 38), (int) $size[0], $best['width']));
}

$out = fopen(CACHE_OUT, 'w');
fputcsv($out, ['group_id', 'name', 'logo_url']);
foreach ($resolved as $groupId => $url) {
    fputcsv($out, [$groupId, '', $url]);
}
fclose($out);

line("checked $checked low-resolution sets, upgraded $upgraded");
line('written to ' . CACHE_OUT . ' - copy to ops/data/set-logos-hires.csv');
