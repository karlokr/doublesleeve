<?php
/**
 * Fetches Japanese set logos from the Bulbagarden Archives.
 *
 * The Western logos come from pokemontcg.io, which catalogues no Japanese sets
 * at all - so the Japanese branch had no artwork of any kind, and the era rows
 * in the menu fell back to borrowing the Western wordmark of the same name.
 * That fallback is fine as a fallback and wrong as an answer: MEGA, ADV, PCG and
 * LEGEND have no Western counterpart to borrow FROM.
 *
 * Japanese expansions are filed under their SET CODE, which is the one identifier
 * that is unambiguous across languages:
 *
 *   S12a VSTAR Universe    -> File:S12a VSTAR Universe Logo.png
 *   SV4a Shiny Treasure ex -> File:SV4a Shiny Treasure ex Logo.png
 *   SV2a Pokémon Card 151  -> File:SV2a Pokémon Card 151 Logo.png
 *
 * so this searches on the abbreviation TCGplayer already gives us rather than on
 * the set name, which is transliterated inconsistently ("Pokemon" vs "Pokémon").
 * A candidate is only accepted when its title actually starts with that code -
 * a wrong logo is worse than none, and the Indonesian reprints sit right beside
 * the originals in the search results.
 *
 * Results are cached in data/set-logos-jp.csv and committed, so provisioning
 * does no network calls.
 *
 *   docker exec -u www-data cryptocards-shop php /provisioning/catalog/fetch-set-logos-jp.php
 *   ... --refresh   re-resolve every set, ignoring the cache
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const GROUPS_CSV = '/provisioning/data/tcgplayer-groups-jp.csv';
const CACHE_CSV = '/provisioning/data/set-logos-jp.csv';
/** The bind mount is read-only, so results are written here and copied out. */
const CACHE_OUT = '/tmp/set-logos-jp.csv';
const API = 'https://archives.bulbagarden.net/w/api.php';
/** Be a good citizen of a volunteer-run wiki. */
const DELAY_US = 400000;

define('REFRESH', in_array('--refresh', $argv ?? [], true));

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Japanese set logos from Bulbagarden Archives\033[0m\n";

function apiGet(array $params): array
{
    $url = API . '?' . http_build_query($params + ['format' => 'json']);
    $context = stream_context_create(['http' => [
        'timeout' => 25,
        'user_agent' => 'DoubleSleeve/1.0 (storefront set-logo backfill)',
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
 * Resolve many File: titles in ONE call.
 *
 * MediaWiki takes up to 50 titles per query, so the whole 454-set catalogue
 * costs ~30 requests instead of ~900. Returns [title => url] for the titles that
 * exist; missing files come back with a negative pageid and no imageinfo, and
 * simply do not appear in the result.
 *
 * @param array<int, string> $titles
 * @return array<string, string>
 */
function fileUrls(array $titles): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($titles)), 50) as $chunk) {
        $data = apiGet([
            'action' => 'query',
            'titles' => implode('|', $chunk),
            'prop' => 'imageinfo',
            'iiprop' => 'url',
        ]);

        /**
         * The API normalises titles (underscores, leading caps) and reports the
         * mapping, so results must be keyed back to what WE asked for or half
         * the hits look like misses.
         */
        $canonical = [];
        foreach ($data['query']['normalized'] ?? [] as $map) {
            $canonical[(string) $map['to']] = (string) $map['from'];
        }
        foreach ($data['query']['pages'] ?? [] as $page) {
            $url = $page['imageinfo'][0]['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $title = (string) ($page['title'] ?? '');
            $out[$canonical[$title] ?? $title] = $url;
        }
        usleep(DELAY_US);
    }

    return $out;
}

/**
 * Candidate file titles for a set, in descending order of confidence.
 *
 * Four conventions are in play on the wiki, all of them real:
 *   S12a VSTAR Universe Logo.png   code + name
 *   SM12a Logo.png                 code alone, when the name never transliterated
 *   SV10 Logo JP.png               "Logo JP", where a Western set shares the code
 *   Jungle Logo.png                bare name, for the 1990s sets
 *
 * The bare-name form is last because it is the only one that can collide with a
 * Western set of the same name - and for the 1996-1999 expansions that share a
 * name across regions, that collision is the correct answer anyway.
 */
function candidateTitles(string $code, string $name): array
{
    // "Pokemon Jungle" is filed as "Jungle"; the game's name is not part of a
    // set's name on the wiki.
    $bare = trim((string) preg_replace('/^Pok[eé]mon\s+/iu', '', $name));

    $titles = [];
    foreach (['png', 'jpg'] as $extension) {
        if ($code !== '') {
            $titles[] = 'File:' . $code . ' ' . $name . ' Logo.' . $extension;
            $titles[] = 'File:' . $code . ' Logo JP.' . $extension;
            $titles[] = 'File:' . $code . ' Logo.' . $extension;
        }
        /**
         * "<Name> Logo JP" only - never the bare "<Name> Logo".
         *
         * Dozens of set names exist in both regions (Black Bolt, White Flare,
         * Battle Academy, Gym Challenge), and the unsuffixed file is always the
         * ENGLISH one, so the bare-name tier quietly put English artwork on
         * Japanese sets. It cannot be caught by comparing URLs afterwards either
         * - the Western copy of that logo comes from pokemontcg.io under a
         * completely different address. The JP suffix and the set code are the
         * only two forms that are unambiguously Japanese.
         */
        $titles[] = 'File:' . $name . ' Logo JP.' . $extension;
        if ($bare !== '' && $bare !== $name) {
            $titles[] = 'File:' . $bare . ' Logo JP.' . $extension;
        }
    }

    /**
     * Decks, gift sets and build boxes have no logo - they are products, not
     * expansions - but the wiki does carry a photo of the box, filed under the
     * product's name with and without spaces. A box shot is the right art for a
     * box, and crucially it is DISTINCT: falling back to the block's expansion
     * logo put the same "Black Collection" wordmark on twenty different decks,
     * which reads as a bug rather than a placeholder.
     *
     * "Contents" shots - the same box laid out open - are excluded; they are
     * photographs of components, not of the thing being sold.
     */
    $squashed = str_replace(' ', '', $name);
    foreach (['jpg', 'png'] as $extension) {
        $titles[] = 'File:' . $name . '.' . $extension;
        if ($squashed !== $name) {
            $titles[] = 'File:' . $squashed . '.' . $extension;
        }
    }

    return $titles;
}

/**
 * Last resort for the sets whose file is named nothing like the set: one search,
 * accepted only when the title OPENS with the set code. A wrong logo is worse
 * than none, and the Indonesian and Traditional Chinese reprints sit directly
 * beside the originals in every result list.
 */
function searchLogo(string $code, string $name): ?string
{
    if ($code === '') {
        return null;
    }
    $data = apiGet([
        'action' => 'query', 'list' => 'search',
        'srsearch' => $code . ' ' . $name . ' logo',
        'srnamespace' => 6, 'srlimit' => 8,
    ]);
    usleep(DELAY_US);

    foreach ($data['query']['search'] ?? [] as $hit) {
        $title = (string) ($hit['title'] ?? '');
        if (!preg_match('/^File:' . preg_quote($code, '/') . '\b/i', $title)
            || !preg_match('/logo/i', $title)) {
            continue;
        }
        $found = fileUrls([$title]);
        if ($found !== []) {
            return reset($found);
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
$cached = [];
if (!REFRESH && is_readable(CACHE_CSV)) {
    $handle = fopen(CACHE_CSV, 'r');
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 3 && trim((string) $row[2]) !== '') {
            $cached[(int) $row[0]] = (string) $row[2];
        }
    }
    fclose($handle);
    line(count($cached) . ' logos already cached');
}

if (!is_readable(GROUPS_CSV)) {
    warn('Japanese groups CSV missing - run the Japanese import first');
    exit(1);
}

$handle = fopen(GROUPS_CSV, 'r');
$header = fgetcsv($handle);
$sets = [];
while (($row = fgetcsv($handle)) !== false) {
    $group = array_combine($header, array_pad($row, count($header), ''));
    $groupId = (int) $group['group_id'];
    if ($groupId === 0 || isset($cached[$groupId])) {
        continue;
    }

    // TCGplayer names carry the code as a prefix ("SV4a: Shiny Treasure ex");
    // the wiki wants it separated from the name.
    $name = trim((string) $group['name']);
    $code = trim((string) $group['abbreviation']);
    if (str_contains($name, ':')) {
        [$prefix, $rest] = explode(':', $name, 2);
        if ($code === '' || strcasecmp(trim($prefix), $code) === 0) {
            $code = $code !== '' ? $code : trim($prefix);
            $name = trim($rest);
        }
    }
    $sets[$groupId] = ['code' => $code, 'name' => $name];
}
fclose($handle);
line(count($sets) . ' sets to resolve');

// Pass 1: every candidate title for every set, in 50-title batches.
$wanted = [];
foreach ($sets as $groupId => $set) {
    foreach (candidateTitles($set['code'], $set['name']) as $title) {
        $wanted[] = $title;
    }
}
$hits = fileUrls($wanted);
line(count($hits) . ' candidate titles exist on the wiki');

$resolved = $cached;
$found = 0;
$unresolved = [];
foreach ($sets as $groupId => $set) {
    foreach (candidateTitles($set['code'], $set['name']) as $title) {
        if (isset($hits[$title])) {
            $resolved[$groupId] = $hits[$title];
            ++$found;
            continue 2;
        }
    }
    $unresolved[$groupId] = $set;
}
line("resolved by name: $found");

// Pass 2: search only for what pass 1 missed.
$searched = 0;
foreach ($unresolved as $groupId => $set) {
    $url = searchLogo($set['code'], $set['name']);
    if ($url !== null) {
        $resolved[$groupId] = $url;
        ++$searched;
        unset($unresolved[$groupId]);
    }
}
line("resolved by search: $searched");

$missing = array_map(static fn ($s) => trim($s['code'] . ' ' . $s['name']), $unresolved);

$out = fopen(CACHE_OUT, 'w');
fputcsv($out, ['group_id', 'name', 'logo_url']);
foreach ($resolved as $groupId => $url) {
    fputcsv($out, [$groupId, '', $url]);
}
fclose($out);

line("resolved this run: $found, cached total: " . count($resolved));
line('written to ' . CACHE_OUT . ' - copy to ops/data/set-logos-jp.csv');
if ($missing !== []) {
    warn(count($missing) . ' unresolved: ' . implode(', ', array_slice($missing, 0, 6)));
}
