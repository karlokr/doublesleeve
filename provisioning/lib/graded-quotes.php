<?php
/**
 * Multi-source price quotes for graded (slabbed) combinations.
 *
 * A slab has no TCGplayer market row, so the raw engine's anchor does not exist
 * for it. What the graded market has instead is SALES - individual auctions of
 * serialised objects - so this collects them from independent families and
 * blends, the same shape as the raw engine:
 *
 *   pricecharting_curve   PriceCharting's own per-grade estimate (their model,
 *                         one number per tier where they carry it)
 *   pricecharting_comps   the sold listings PriceCharting displays under the
 *                         card, filtered to this company + tier
 *   ebay_130point         130point.com's eBay sold-listing search - fresher
 *                         than PriceCharting's comps and independently sourced
 *
 * Comps are recency-weighted: a slab market can double in a quarter (early
 * supply of a new set dries up), so an all-time median lags badly - it is
 * exactly how this shop once priced a CGC 10 at its spring level in August.
 * Weight halves every RECENCY_HALF_LIFE_DAYS.
 *
 * Every function returns quotes in USD; FX is the engine's job.
 */
declare(strict_types=1);

/**
 * 21 days, not a quarter. Slab markets are thin and step quickly - early supply
 * of a new set dries up and the clearing price moves 30% in a month. At a
 * 60-day half-life a July cluster of sales outvoted the three most recent ones
 * and priced a CGC 10 about 20% under where Collectr and the newest comps put
 * it; three weeks keeps the estimate on the current step while MAX_MOVE_PCT in
 * the engine still smooths any jump.
 */
const RECENCY_HALF_LIFE_DAYS = 21;
const COMPS_MAX_SAMPLE = 12;

/** PriceCharting's structured columns, by the tier they actually mean. */
const PC_CURVE_COLUMNS = [
    // manual_only is PSA 10 SPECIFICALLY - it must not price another company's 10.
    'PSA|10 Gem Mint' => 'manual_only_price',
    '*|9.5 Gem Mint' => 'box_only_price',
    '*|9' => 'graded_price',
    '*|8' => 'new_price',
    '*|7' => 'complete_price',
];

/**
 * Does a sale title describe this company + tier?
 *
 * Titles are auction-speak. The tier test leans on what sellers reliably write:
 * the numeral always appears; the epithet appears when (and only when) it is the
 * expensive distinction - nobody selling a Pristine forgets to say Pristine,
 * which is also why a bare "CGC 10" is a Gem Mint and must EXCLUDE the labelled
 * tiers rather than match them.
 */
function gradedTitleMatches(string $title, string $company, string $tier): bool
{
    $t = strtolower($title);
    if (!preg_match('/\b' . preg_quote(strtolower($company), '/') . '\b/', $t)) {
        return false;
    }

    return match ($tier) {
        '10 Black Label' => str_contains($t, 'black label'),
        '10 Pristine' => (bool) preg_match('/pristine|perfect/', $t),
        '10 Gem Mint' => preg_match('/\b10\b/', $t)
            && !preg_match('/pristine|black label|perfect/', $t),
        '9.5 Gem Mint' => str_contains($t, '9.5'),
        default => preg_match('/\b' . preg_quote($tier, '/') . '\b/', $t)
            && !str_contains($t, $tier . '.5'),
    };
}

/** Recency-weighted median of [usd, isoDate] pairs. */
function compsEstimate(array $sales): ?array
{
    if ($sales === []) {
        return null;
    }
    usort($sales, static fn ($a, $b) => strcmp($b[1], $a[1]));
    $sales = array_slice($sales, 0, COMPS_MAX_SAMPLE);

    $now = time();
    $weighted = [];
    foreach ($sales as [$usd, $date]) {
        $age = max(0, ($now - (strtotime($date) ?: $now)) / 86400);
        $weighted[] = ['usd' => (float) $usd, 'w' => 0.5 ** ($age / RECENCY_HALF_LIFE_DAYS)];
    }
    usort($weighted, static fn ($a, $b) => $a['usd'] <=> $b['usd']);

    $total = array_sum(array_column($weighted, 'w'));
    $running = 0.0;
    foreach ($weighted as $entry) {
        $running += $entry['w'];
        if ($running >= $total / 2) {
            return ['usd' => $entry['usd'], 'n' => count($weighted)];
        }
    }

    return ['usd' => end($weighted)['usd'], 'n' => count($weighted)];
}

function gradedFetch(string $url, ?string $post = null): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64)',
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return is_string($body) && $status === 200 && strlen($body) > 500 ? $body : null;
}

/** Source 1: PriceCharting's structured per-tier estimate. */
function quotePricechartingCurve(string $html, string $company, string $tier): ?array
{
    $column = PC_CURVE_COLUMNS[$company . '|' . $tier] ?? PC_CURVE_COLUMNS['*|' . $tier] ?? null;
    if ($column === null) {
        return null;
    }
    if (!preg_match('/id="' . $column . '"[^>]*>\s*<span class="price js-price">\s*\$([\d,.]+)/', $html, $m)) {
        return null;
    }

    return ['usd' => (float) str_replace(',', '', $m[1]), 'n' => 1];
}

/**
 * Which of PriceCharting's sold-listing buckets holds this company + tier.
 *
 * The card page carries one table PER TIER (revealed by the
 * completed-auctions-condition select), which beats guessing from titles: their
 * admins have already sorted every sale into the right bucket. Company-specific
 * tens get their own bucket; the numeric grades share a "Grade N" bucket across
 * companies, so those still need the title filter afterwards.
 *
 * Naming note: PC's "BGS 10" bucket IS the Pristine tier - Beckett's 10 is
 * named Pristine - and "BGS 10 Black" is the all-10-subgrades Black Label.
 */
function pcBucketLabel(string $company, string $tier): ?string
{
    return match ($company . '|' . $tier) {
        'PSA|10 Gem Mint' => 'PSA 10',
        'CGC|10 Gem Mint' => 'CGC 10',
        'CGC|10 Pristine' => 'CGC 10 Prist.',
        'BGS|10 Pristine' => 'BGS 10',
        'BGS|10 Black Label' => 'BGS 10 Black',
        'SGC|10 Gem Mint' => 'SGC 10',
        'TAG|10 Gem Mint' => 'TAG 10',
        'ACE|10 Gem Mint' => 'ACE 10',
        default => str_contains($tier, 'Gem Mint') || preg_match('/^[0-9.]+$/', $tier)
            ? 'Grade ' . trim(str_replace('Gem Mint', '', $tier))
            : null,
    };
}

/** Source 2: the sold comps on the PriceCharting page, from this tier's own table. */
function quotePricechartingComps(string $html, string $company, string $tier): ?array
{
    $label = pcBucketLabel($company, $tier);
    if ($label === null) {
        return null;
    }

    // label -> bucket class, from the page's own tier dropdown.
    if (!preg_match_all(
        '/<option value="(completed-auctions-[a-z-]+)">\s*([^(<]+?)\s*\((\d+)\)/',
        $html,
        $options,
        PREG_SET_ORDER
    )) {
        return null;
    }
    $bucket = null;
    foreach ($options as $option) {
        if ($option[2] === $label && (int) $option[3] > 0) {
            $bucket = $option[1];
            break;
        }
    }
    if ($bucket === null) {
        return null;
    }

    $start = strpos($html, '<div class="' . $bucket . '">');
    if ($start === false) {
        return null;
    }
    $end = strpos($html, '<div class="completed-auctions-', $start + 20);
    $section = substr($html, $start, $end !== false ? $end - $start : 200000);

    // The company-specific buckets are pre-sorted; the shared "Grade N" buckets
    // mix companies and still need the title test.
    $generic = str_starts_with($label, 'Grade ');

    $sales = [];
    foreach (preg_split('/<tr/', $section) as $chunk) {
        // Best-offer sales render their ACCEPTED price with a title attribute
        // (and the asking price beside it as listed-price-inline, which must not
        // match - a sale is what was paid, not what was asked).
        if (!preg_match('/class="js-ebay-completed-sale"[^>]*>\s*([^<]{10,200})<\/a>/', $chunk, $title)
            || !preg_match('/<span class="js-price"(?![^>]*listed-price)[^>]*>\$([\d,.]+)<\/span>/', $chunk, $price)) {
            continue;
        }
        if ($generic && !gradedTitleMatches($title[1], $company, $tier)) {
            continue;
        }
        preg_match('/(\d{4}-\d{2}-\d{2})/', $chunk, $date);
        $sales[] = [(float) str_replace(',', '', $price[1]), $date[1] ?? date('Y-m-d', time() - 45 * 86400)];
    }

    return compsEstimate($sales);
}

/**
 * Source 3: 130point's eBay sold-listing search.
 *
 * The only source that has to identify the CARD from a title, so it is held to
 * the strictest test: the collector number must appear ($require). Without it
 * this source priced an alt-art Umbreon off sales of every other Umbreon in the
 * set - precision over recall, corroboration is worthless when it corroborates
 * the wrong card.
 */
function quote130Point(string $query, string $company, string $tier, ?string $require, ?string $exclude): ?array
{
    $html = gradedFetch(
        'https://back.130point.com/sales/',
        http_build_query(['query' => $query, 'type' => 2, 'subcat' => -1])
    );
    if ($html === null) {
        return null;
    }

    $sales = [];
    foreach (preg_split('/<tr id="dRow"/', $html) as $chunk) {
        if (!preg_match('/data-price="([\d.]+)"/', $chunk, $price)
            || !preg_match('/data-currency="([A-Z]+)"/', $chunk, $currency)
            || $currency[1] !== 'USD'
            || !preg_match('/(\d{4}-\d{2}-\d{2})/', $chunk, $date)) {
            continue;
        }
        $title = strip_tags(substr($chunk, 0, 4000));
        if ($require !== null && $require !== '' && !preg_match('/' . $require . '/i', $title)) {
            continue;
        }
        if ($exclude !== null && $exclude !== '' && preg_match('/' . $exclude . '/i', $title)) {
            continue;
        }
        if (gradedTitleMatches($title, $company, $tier)) {
            $sales[] = [(float) $price[1], $date[1]];
        }
    }

    return compsEstimate($sales);
}

/**
 * Blend the source votes: weighted median, sales-backed sources weighted by how
 * many sales stand behind them, the curve counting as a single corroborating
 * vote. Returns null when no source spoke.
 *
 * @param array<string, array{usd: float, n: int}|null> $votes
 * @return array{usd: float, families: int, spread_pct: float}|null
 */
function blendGradedQuotes(array $votes): ?array
{
    $votes = array_filter($votes);
    if ($votes === []) {
        return null;
    }

    $weighted = [];
    foreach ($votes as $source => $vote) {
        $weight = $source === 'pricecharting_curve' ? 1.0 : 1.0 + min((int) $vote['n'], 6) * 0.25;
        $weighted[] = ['usd' => $vote['usd'], 'w' => $weight];
    }
    usort($weighted, static fn ($a, $b) => $a['usd'] <=> $b['usd']);

    $total = array_sum(array_column($weighted, 'w'));
    $running = 0.0;
    $usd = end($weighted)['usd'];
    foreach ($weighted as $entry) {
        $running += $entry['w'];
        if ($running >= $total / 2) {
            $usd = $entry['usd'];
            break;
        }
    }

    $values = array_column($weighted, 'usd');
    $spread = min($values) > 0 ? (max($values) - min($values)) / min($values) * 100 : 0.0;

    return ['usd' => $usd, 'families' => count($votes), 'spread_pct' => $spread];
}
