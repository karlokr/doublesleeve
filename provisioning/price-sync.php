<?php
/**
 * DoubleSleeve price engine.
 *
 * Pulls market data from independent source families, blends them, scores how much
 * it trusts the result, and either proposes or applies a new price. Every decision
 * is journaled whether or not it changes anything.
 *
 * DRY RUN BY DEFAULT. It will not touch a single price until you pass --apply.
 * Run it for a couple of weeks, read the journal, and only then promote it:
 *
 *   make price-sync           # propose only, writes journal
 *   make price-report         # read what it would have done
 *   make price-apply          # actually reprice, still fully gated
 *
 * Why not a plain average of sources: see docs/operations-pipeline.md §1.2.
 * TCGplayer is the ANCHOR - it is the market this shop sells into - and TCGCSV and
 * pokemontcg.io both mirror it, so they count as one vote rather than two.
 * Cardmarket (EU) is corroboration only: it moves confidence, never the price.
 * Averaging the two would price the shop off a market it does not sell in; they
 * disagreed by 55% on average across the catalogue in the first dry run.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';
require_once __DIR__ . '/lib/graded-quotes.php';

// --- policy ----------------------------------------------------------------
const MAX_MOVE_PCT = 15.0;   // never move a price more than this per cycle
const SANITY_MULT = 5.0;     // >5x swing is a bad product match, not a real spike
const MIN_PRICE = 0.25;      // below this, listing costs more than the card earns
const FX_BUFFER = 0.02;      // absorb intra-day FX drift rather than eroding margin
const MARGIN = 1.00;         // 1.00 = sell at blended market; raise for a markup

/**
 * Percentage guards are meaningless on bulk commons: a 1c card moving to 21c is a
 * "2000% move" that is really 20c of floor noise. Below this price, judge moves in
 * absolute dollars instead.
 */
const PCT_GUARD_MIN_PRICE = 2.00;
const ABS_MOVE_LIMIT = 1.00;

/**
 * Cross-market agreement bands. These are wide on purpose: TCGplayer (NA) and
 * Cardmarket (EU) are separate economies and a 40% gap on the same card is
 * ordinary. Beyond the second band the two sources have probably matched
 * different printings, which is worth a human look.
 */
const SPREAD_CROSS_MARKET = 45.0;
const SPREAD_CROSS_MARKET_MAX = 120.0;

$apply = in_array('--apply', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
}

$runId = substr(md5(uniqid('run', true)), 0, 16);
$db = Db::getInstance();

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Price sync " . ($apply ? "\033[31m[APPLY]\033[0m" : '[dry run]') . "\033[0m  run=$runId\n";

// ---------------------------------------------------------------------------
// FX — Bank of Canada, the authoritative free source for CAD pairs
// ---------------------------------------------------------------------------
/**
 * pokemontcg.io returns intermittent 500s under load. Without retries roughly
 * two thirds of a run's snapshots go missing, which silently collapses products
 * to a single source family and parks them all in the review queue.
 */
function fetchJson(string $url, int $timeout = 30, int $attempts = 4): ?array
{
    for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'DoubleSleeve/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $status < 400) {
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($attempt < $attempts) {
            sleep($attempt * 3);
        }
    }

    return null;
}

function refreshFx(string $pair, string $series): ?float
{
    $data = fetchJson("https://www.bankofcanada.ca/valet/observations/$series/json?recent=1");
    $observation = $data['observations'][0] ?? null;
    if (!$observation || !isset($observation[$series]['v'])) {
        return null;
    }
    $rate = (float) $observation[$series]['v'];
    Db::getInstance()->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_fx (pair, rate, observed, date_add)
         VALUES ("' . pSQL($pair) . '", ' . $rate . ', "' . pSQL((string) $observation['d']) . '", NOW())
         ON DUPLICATE KEY UPDATE rate = VALUES(rate), observed = VALUES(observed), date_add = NOW()'
    );

    return $rate;
}

function storedFx(string $pair): ?float
{
    $value = Db::getInstance()->getValue(
        'SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "' . pSQL($pair) . '"'
    );

    return $value === false || $value === null ? null : (float) $value;
}

$usdCad = refreshFx('USDCAD', 'FXUSDCAD') ?? storedFx('USDCAD');
$eurCad = refreshFx('EURCAD', 'FXEURCAD') ?? storedFx('EURCAD');

if ($usdCad === null) {
    warn('no USD/CAD rate available (live or cached) - aborting, pricing would be wrong');
    exit(1);
}
line(sprintf('FX: USD/CAD %.4f, EUR/CAD %s', $usdCad, $eurCad !== null ? sprintf('%.4f', $eurCad) : 'unavailable'));

// ---------------------------------------------------------------------------
// gather source data
// ---------------------------------------------------------------------------
$rows = $db->executeS(
    'SELECT p.id_product, p.reference, p.price AS current_price,
            m.kind, m.pokemontcg_card_id, m.tcgplayer_product_id, m.tcgplayer_group_id,
            m.variant_key, m.tcgplayer_subtype,
            IFNULL(pol.frozen, 0) AS frozen, pol.cost_basis, pol.margin_override
       FROM ' . _DB_PREFIX_ . 'product p
       JOIN ' . _DB_PREFIX_ . 'price_source_map m ON m.reference = p.reference
       LEFT JOIN ' . _DB_PREFIX_ . 'price_policy pol ON pol.id_product = p.id_product
      WHERE p.active = 1
      ORDER BY p.id_product' . ($limit > 0 ? ' LIMIT ' . $limit : '')
) ?: [];

line(count($rows) . ' products with mapped sources');

/**
 * TCGplayer market prices, one call per set group rather than per product.
 *
 * Keyed by productId AND subtype. A vintage card number maps to several printings
 * (Unlimited, Shadowless, 1st Edition) whose prices differ by an order of magnitude,
 * so we must select the printing we actually stock rather than collapse them.
 */
/**
 * Which TCGplayer category a group lives in - the price URL needs it, and it was
 * hardcoded to 3 (Western), so every Japanese product silently fetched nothing
 * and journaled "no source returned a price" twice a day.
 */
$jpGroups = [];
if (($fh = @fopen('/provisioning/data/tcgplayer-groups-jp.csv', 'r')) !== false) {
    fgetcsv($fh);
    while (($csv = fgetcsv($fh)) !== false) {
        $jpGroups[(int) $csv[0]] = true;
    }
    fclose($fh);
}

$tcgPrices = [];
$groups = array_unique(array_filter(array_column($rows, 'tcgplayer_group_id')));
foreach ($groups as $groupId) {
    $category = isset($jpGroups[(int) $groupId]) ? 85 : 3;
    $data = fetchJson('https://tcgcsv.com/tcgplayer/' . $category . '/' . (int) $groupId . '/prices', 60);
    foreach ($data['results'] ?? [] as $entry) {
        if (empty($entry['marketPrice'])) {
            continue;
        }
        $tcgPrices[(int) $entry['productId']][(string) $entry['subTypeName']] = (float) $entry['marketPrice'];
    }
}
line(count($tcgPrices) . ' TCGplayer products priced across ' . count($groups) . ' set groups');

/** pokemontcg.io carries BOTH a TCGplayer snapshot and Cardmarket (EUR). */
$pokePrices = [];
$cardIds = array_filter(array_column($rows, 'pokemontcg_card_id'));
$setIds = [];
foreach ($cardIds as $cardId) {
    $setIds[explode('-', $cardId)[0]] = true;
}
foreach (array_keys($setIds) as $setId) {
    /**
     * Paged. A single 250-card page silently dropped the tail of any larger set -
     * the SWSH promo series has 304 cards, so every card past 250 lost its
     * corroborating family and sat in needs_review forever, looking exactly like
     * an API outage that never healed.
     */
    $cards = [];
    for ($page = 1; $page <= 4; ++$page) {
        $data = fetchJson('https://api.pokemontcg.io/v2/cards?q=set.id:' . rawurlencode($setId)
            . '&pageSize=250&page=' . $page, 90);
        $cards = array_merge($cards, $data['data'] ?? []);
        if (count($data['data'] ?? []) < 250) {
            break;
        }
    }
    foreach ($cards as $card) {
        // Keep every printing separately. 63% of cards exist as two or more
        // (normal + reverse holo on modern, 1st edition + unlimited on vintage)
        // and they are different products at different prices - collapsing them
        // with max() prices a common at its 1st-edition-holo value.
        $byVariant = [];
        foreach (($card['tcgplayer']['prices'] ?? []) as $variantKey => $variant) {
            $value = $variant['market'] ?? $variant['mid'] ?? null;
            if ($value) {
                $byVariant[(string) $variantKey] = (float) $value;
            }
        }

        // Cardmarket splits only plain vs reverse holo.
        $cmPrices = $card['cardmarket']['prices'] ?? [];
        $pokePrices[$card['id']] = [
            'tcgplayer' => $byVariant,
            'cardmarket' => [
                'reverse' => $cmPrices['reverseHoloTrend'] ?? $cmPrices['reverseHoloAvg30'] ?? null,
                'plain' => $cmPrices['trendPrice'] ?? $cmPrices['avg30'] ?? null,
            ],
        ];
    }
}
line(count($pokePrices) . ' pokemontcg.io price snapshots fetched');

// ---------------------------------------------------------------------------
// blend + decide
// ---------------------------------------------------------------------------
function median(array $values): float
{
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

$stats = ['applied' => 0, 'proposed' => 0, 'unchanged' => 0, 'no_data' => 0,
          'clamped' => 0, 'floor' => 0, 'sanity' => 0, 'frozen' => 0, 'review' => 0];

foreach ($rows as $row) {
    $productId = (int) $row['id_product'];
    $current = (float) $row['current_price'];

    // --- collect per family, for THIS product's printing --------------------
    $variantKey = (string) $row['variant_key'];
    $subtype = (string) $row['tcgplayer_subtype'];

    $tcgFamily = [];
    $productPrices = $tcgPrices[(int) $row['tcgplayer_product_id']] ?? [];

    // The product price is the BASE that combination impacts hang off, and
    // sku-rebuild sets that base to the cheapest printing at Near Mint. The engine
    // has to target the same thing or every SKU delta drifts.
    $basePrinting = $subtype;
    if ($row['kind'] === 'single' && count($productPrices) > 1) {
        $basePrinting = (string) array_search(min($productPrices), $productPrices, true);
    }

    if ($productPrices) {
        // Exact printing only. Falling back to "any subtype" is what produced a
        // $2.39 Base Haunter priced at $26.84 off its 1st-edition-holo variant.
        if ($basePrinting !== '' && isset($productPrices[$basePrinting])) {
            $tcgFamily[] = $productPrices[$basePrinting] * $usdCad;
        } elseif (count($productPrices) === 1) {
            $tcgFamily[] = reset($productPrices) * $usdCad;
        }
    }

    $poke = $pokePrices[$row['pokemontcg_card_id']] ?? null;
    if ($poke && $variantKey !== '' && isset($poke['tcgplayer'][$variantKey])) {
        $tcgFamily[] = $poke['tcgplayer'][$variantKey] * $usdCad;
    } elseif ($poke && $variantKey === '' && count($poke['tcgplayer']) === 1) {
        $tcgFamily[] = reset($poke['tcgplayer']) * $usdCad;
    }

    $families = [];
    $sources = ['variant' => $basePrinting ?: ($variantKey ?: $subtype)];
    if ($tcgFamily) {
        // TCGCSV and pokemontcg.io both mirror TCGplayer - one vote, not two.
        $families['tcgplayer'] = median($tcgFamily);
        $sources['tcgplayer'] = array_map(static fn ($v) => round($v, 2), $tcgFamily);
    }

    // Match Cardmarket's plain/reverse split to the printing we hold.
    if ($poke && $eurCad !== null) {
        $isReverse = str_contains(strtolower($variantKey), 'reverse');
        $cm = $isReverse ? $poke['cardmarket']['reverse'] : $poke['cardmarket']['plain'];
        if ($cm) {
            $families['cardmarket'] = (float) $cm * $eurCad;
            $sources['cardmarket'] = round($families['cardmarket'], 2);
        }
    }

    if (!$families) {
        ++$stats['no_data'];
        journal($runId, $productId, (string) $row['reference'], $current, $current, null,
            0.0, 0, 0.0, 'low', 'no_data', 'no source returned a price', []);
        continue;
    }

    // --- blend --------------------------------------------------------------
    // TCGplayer is the ANCHOR, not an equal voter. You sell into the North
    // American market in CAD; Cardmarket is a different continent with its own
    // supply and demand and routinely differs by 50%+ on the same card. Taking a
    // median across the two would price your shop off a market you don't sell in.
    // Cardmarket's job is corroboration: it moves confidence, never the price.
    if (isset($families['tcgplayer'])) {
        $market = $families['tcgplayer'];
        $anchor = 'tcgplayer';
    } else {
        $market = reset($families);
        $anchor = (string) array_key_first($families);
    }

    $spread = 0.0;
    if (isset($families['tcgplayer'], $families['cardmarket'])) {
        $spread = (abs($families['tcgplayer'] - $families['cardmarket']) / max($market, 0.01)) * 100;
    }

    // --- confidence ---------------------------------------------------------
    // Sealed product has no Cardmarket equivalent, so a second family is simply
    // unavailable - treating that as "low" would park all sealed in review forever.
    // A lone source is medium: good enough to track, still gated on move size.
    // Cross-market tolerance is deliberately wide: TCGplayer and Cardmarket are
    // different economies, so a 40% gap is normal, not suspicious. What we care
    // about is whether Cardmarket broadly agrees on the card's magnitude - a 3x
    // disagreement usually means the two sources matched different printings.
    $familyCount = count($families);
    $corroborationPossible = $row['pokemontcg_card_id'] !== '';
    $anchored = $anchor === 'tcgplayer';

    if ($anchored && $familyCount >= 2 && $spread < SPREAD_CROSS_MARKET) {
        $confidence = 'high';
    } elseif ($anchored && $familyCount >= 2 && $spread < SPREAD_CROSS_MARKET_MAX) {
        $confidence = 'medium';
    } elseif ($anchored && !$corroborationPossible) {
        // Sealed product: no Cardmarket equivalent exists, so a lone anchor is the
        // best available. Still gated on move size below.
        $confidence = 'medium';
    } elseif ($anchored) {
        $confidence = 'low';
    } else {
        // No TCGplayer anchor at all - pricing off a market we don't sell in.
        $confidence = 'low';
    }

    // --- policy -------------------------------------------------------------
    $margin = $row['margin_override'] !== null ? (float) $row['margin_override'] : MARGIN;
    $proposed = round($market * $margin * (1 + FX_BUFFER), 2);

    $movePct = $current > 0 ? abs(($proposed - $current) / $current) * 100 : 100.0;
    $moveAbs = abs($proposed - $current);

    // On cheap cards, judge the move in dollars; percentages are noise down there.
    $usePercentGuards = max($current, $proposed) >= PCT_GUARD_MIN_PRICE;

    // --- guard rails --------------------------------------------------------
    $action = 'propose';
    $reason = '';

    if ((int) $row['frozen'] === 1) {
        $action = 'frozen';
        $reason = 'price frozen by policy';
        ++$stats['frozen'];
    } elseif ($usePercentGuards && $current > 0
        && ($proposed > $current * SANITY_MULT || $proposed < $current / SANITY_MULT)) {
        $action = 'skipped_sanity';
        $reason = sprintf('%.0f%% swing exceeds sanity limit - likely a bad product match', $movePct);
        ++$stats['sanity'];
    } elseif (!$usePercentGuards && $moveAbs > ABS_MOVE_LIMIT) {
        $action = 'skipped_sanity';
        $reason = sprintf('$%.2f move on a sub-$%.2f card', $moveAbs, PCT_GUARD_MIN_PRICE);
        ++$stats['sanity'];
    } elseif ($proposed < MIN_PRICE || ($row['cost_basis'] !== null && $proposed < (float) $row['cost_basis'])) {
        // Raise to the floor rather than skipping. Skipping was worse than useless:
        // it left bulk commons sitting at their old $0.01 price - i.e. BELOW the
        // floor the guard exists to enforce.
        $floor = max(MIN_PRICE, (float) ($row['cost_basis'] ?? 0));
        $reason = sprintf('market %.2f below floor - listing at floor %.2f', $proposed, $floor);
        $proposed = round($floor, 2);
        $action = abs($proposed - $current) < 0.01 ? 'unchanged' : 'floored';
        ++$stats['floor'];
    } elseif ($confidence === 'low') {
        $action = 'needs_review';
        $reason = sprintf('%d family/families, spread %.1f%%', $familyCount, $spread);
        ++$stats['review'];
    } elseif ($usePercentGuards && $movePct > MAX_MOVE_PCT) {
        // Clamp rather than reject: move toward the market, just not all at once.
        $direction = $proposed > $current ? 1 : -1;
        $proposed = round($current * (1 + $direction * MAX_MOVE_PCT / 100), 2);
        $action = 'clamped';
        $reason = sprintf('move %.1f%% clamped to %.0f%%', $movePct, MAX_MOVE_PCT);
        ++$stats['clamped'];
    } elseif ($usePercentGuards && $confidence === 'medium' && $movePct > 10.0) {
        $action = 'needs_review';
        $reason = sprintf('medium confidence with a %.1f%% move', $movePct);
        ++$stats['review'];
    } elseif (abs($proposed - $current) < 0.01) {
        $action = 'unchanged';
        ++$stats['unchanged'];
    }

    // --- apply --------------------------------------------------------------
    $appliedPrice = null;
    $writable = in_array($action, ['propose', 'clamped', 'floored'], true);

    if ($apply && $writable) {
        $db->update('product', ['price' => (float) $proposed], 'id_product = ' . $productId);
        $db->update('product_shop', ['price' => (float) $proposed], 'id_product = ' . $productId);
        // Combinations store a DELTA from the product price, so moving the base
        // without rewriting them silently corrupts every SKU's price.
        repriceCombinations($productId, $productPrices, $usdCad, (float) $proposed);
        $appliedPrice = $proposed;
        $action = 'applied';
        ++$stats['applied'];
    } elseif ($writable) {
        ++$stats['proposed'];
    }

    journal($runId, $productId, (string) $row['reference'], $current, $proposed, $appliedPrice,
        $market, $familyCount, $spread, $confidence, $action, $reason, $sources);
}

/**
 * Rewrites every combination's price impact after the product base moves.
 *
 * A combination's `price` is a delta from the product price, so repricing the
 * product without this leaves each SKU carrying a stale offset - a reverse holo
 * that was +$2.70 above a $0.30 base stays +$2.70 above a new $0.50 base.
 * Each SKU is recomputed from its OWN printing's market price.
 */
function repriceCombinations(int $productId, array $printingPricesUsd, float $usdCad, float $base): void
{
    if (!$printingPricesUsd) {
        return;
    }

    $ladder = [
        'Near Mint' => 1.00, 'Lightly Played' => 0.85, 'Moderately Played' => 0.70,
        'Heavily Played' => 0.55, 'Damaged' => 0.40,
    ];
    $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

    $rows = Db::getInstance()->executeS(
        'SELECT pa.id_product_attribute,
                MAX(CASE WHEN ag.name = "Printing" THEN al.name END)  AS printing,
                MAX(CASE WHEN ag.name = "Condition" THEN al.name END) AS grade
           FROM ' . _DB_PREFIX_ . 'product_attribute pa
           JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
           JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al
                ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $defaultLang . '
           JOIN ' . _DB_PREFIX_ . 'attribute_group_lang ag
                ON ag.id_attribute_group = a.id_attribute_group AND ag.id_lang = ' . $defaultLang . '
          WHERE pa.id_product = ' . $productId . '
          GROUP BY pa.id_product_attribute'
    ) ?: [];

    foreach ($rows as $row) {
        $printing = (string) $row['printing'];
        $grade = (string) $row['grade'];
        if (!isset($printingPricesUsd[$printing], $ladder[$grade])) {
            continue;
        }
        $impact = round(($printingPricesUsd[$printing] * $usdCad * $ladder[$grade]) - $base, 2);
        Db::getInstance()->update(
            'product_attribute',
            ['price' => $impact],
            'id_product_attribute = ' . (int) $row['id_product_attribute']
        );
        Db::getInstance()->update(
            'product_attribute_shop',
            ['price' => $impact],
            'id_product_attribute = ' . (int) $row['id_product_attribute']
        );
    }
}

function journal(
    string $runId, int $productId, string $reference, float $old, float $proposed, ?float $applied,
    float $market, int $families, float $spread, string $confidence, string $action,
    string $reason, array $sources
): void {
    // Raw INSERT, not Db::insert(): the helper coerces PHP null to 0, which made
    // every dry-run row look like it had been applied at $0.00. In an audit table
    // whose whole purpose is proving what did and did not change, that is fatal.
    Db::getInstance()->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'price_journal
            (id_product, reference, run_id, old_price, proposed_price, applied_price,
             market_cad, families, spread_pct, confidence, action, reason, sources, date_add)
         VALUES (' . $productId . ', "' . pSQL($reference) . '", "' . pSQL($runId) . '",
                 ' . $old . ', ' . $proposed . ', ' . ($applied === null ? 'NULL' : (float) $applied) . ',
                 ' . $market . ', ' . $families . ', ' . round($spread, 4) . ',
                 "' . pSQL($confidence) . '", "' . pSQL($action) . '", "' . pSQL($reason) . '",
                 "' . pSQL(json_encode($sources), true) . '", NOW())'
    );
}

// ---------------------------------------------------------------------------
// graded combinations - priced from sales, not from the raw ladder
// ---------------------------------------------------------------------------
/**
 * Slabs have no TCGplayer row, so the raw anchor does not exist for them; their
 * market is auctions. Three independent families (see lib/graded-quotes.php):
 * PriceCharting's per-tier estimate, PriceCharting's sold comps, and 130point's
 * eBay sold search - blended by weighted median, recency-weighted, guarded by
 * the same rails as the raw engine, journaled under the combination reference.
 *
 * Runs AFTER the raw pass on purpose: a combination price is a DELTA from the
 * product base, and the raw pass may have just moved that base. Each slab's
 * pre-run TOTAL was captured before anything moved; if every source is down the
 * delta is re-anchored so the shelf price a customer sees does not drift just
 * because the raw card moved underneath it.
 */
$slabs = $db->executeS(
    'SELECT g.combo_reference, g.pricecharting_slug, g.query130, g.require_pattern, g.exclude_pattern,
            g.company, g.tier,
            pa.id_product_attribute, pa.id_product, pa.price AS impact,
            p.price AS base, IFNULL(pol.frozen, 0) AS frozen
       FROM ' . _DB_PREFIX_ . 'graded_source_map g
       JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pa.reference = g.combo_reference
       JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product
       LEFT JOIN ' . _DB_PREFIX_ . 'price_policy pol ON pol.id_product = pa.id_product
      WHERE p.active = 1'
) ?: [];
line(count($slabs) . ' graded combinations with mapped sources');

$slabBaseline = [];
foreach ($slabs as $slab) {
    // Captured from the same pre-pass read the raw engine saw: $rows carried the
    // base BEFORE any apply, and impacts are only written below.
    $slabBaseline[$slab['combo_reference']] = (float) $slab['base'] + (float) $slab['impact'];
}

$pcPages = [];
$gradedStats = ['applied' => 0, 'proposed' => 0, 'unchanged' => 0, 'review' => 0, 'held' => 0];

foreach ($slabs as $slab) {
    $comboRef = (string) $slab['combo_reference'];
    $comboId = (int) $slab['id_product_attribute'];
    $productId = (int) $slab['id_product'];
    $company = (string) $slab['company'];
    $tier = (string) $slab['tier'];
    $baseline = $slabBaseline[$comboRef];

    // The base as it stands NOW - the raw pass may have rewritten it above.
    $baseNow = (float) $db->getValue(
        'SELECT price FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . $productId
    );

    if ((int) $slab['frozen'] === 1) {
        journal($runId, $productId, $comboRef, $baseline, $baseline, null,
            0.0, 0, 0.0, 'low', 'frozen', 'price frozen by policy', []);
        continue;
    }

    // One PriceCharting fetch per card page, shared by its slabs and both PC sources.
    $slug = (string) $slab['pricecharting_slug'];
    if ($slug !== '' && !array_key_exists($slug, $pcPages)) {
        $pcPages[$slug] = gradedFetch('https://www.pricecharting.com/game/' . $slug);
    }
    $page = $slug !== '' ? $pcPages[$slug] : null;

    $votes = [
        'pricecharting_curve' => $page !== null ? quotePricechartingCurve($page, $company, $tier) : null,
        'pricecharting_comps' => $page !== null ? quotePricechartingComps($page, $company, $tier) : null,
        'ebay_130point' => $slab['query130'] !== ''
            ? quote130Point((string) $slab['query130'], $company, $tier,
                (string) ($slab['require_pattern'] ?? ''), (string) $slab['exclude_pattern'])
            : null,
    ];
    $blend = blendGradedQuotes($votes);
    // Politeness: both sales sources rate-limit by IP (130point returns
    // Cloudflare 1015 under bursts); a paced crawl twice a day stays welcome.
    usleep(1500000);

    $sources = ['tier' => $company . ' ' . $tier];
    foreach ($votes as $name => $vote) {
        if ($vote !== null) {
            $sources[$name] = ['usd' => round($vote['usd'], 2), 'sales' => $vote['n']];
        }
    }

    if ($blend === null) {
        // Sources down: hold the shelf price steady against whatever the base
        // did, rather than letting the delta silently reprice the slab.
        $impact = round($baseline - $baseNow, 2);
        if ($apply && abs($impact - (float) $slab['impact']) >= 0.01) {
            $db->update('product_attribute', ['price' => $impact], 'id_product_attribute = ' . $comboId);
            $db->update('product_attribute_shop', ['price' => $impact], 'id_product_attribute = ' . $comboId);
        }
        ++$gradedStats['held'];
        journal($runId, $productId, $comboRef, $baseline, $baseline, null,
            0.0, 0, 0.0, 'low', 'held', 'no graded source reachable - total re-anchored', $sources);
        continue;
    }

    $marketCad = $blend['usd'] * $usdCad;
    $proposed = round($marketCad * MARGIN * (1 + FX_BUFFER), 2);
    $movePct = $baseline > 0 ? abs(($proposed - $baseline) / $baseline) * 100 : 100.0;

    /**
     * Confidence mirrors the raw engine's shape. Two independent sales families
     * agreeing within the cross-market band is high; a lone family carrying at
     * least three actual sales is medium; PriceCharting's PSA-10 column alone is
     * medium too (it is their blended estimate of exactly this tier). Anything
     * thinner goes to review instead of moving money.
     */
    $curveExact = $votes['pricecharting_curve'] !== null && $company === 'PSA' && $tier === '10 Gem Mint';
    $salesBacked = max($votes['pricecharting_comps']['n'] ?? 0, $votes['ebay_130point']['n'] ?? 0);
    if ($blend['families'] >= 2 && $blend['spread_pct'] < SPREAD_CROSS_MARKET) {
        $confidence = 'high';
    } elseif ($blend['families'] >= 2 || $salesBacked >= 3 || $curveExact) {
        $confidence = 'medium';
    } else {
        $confidence = 'low';
    }

    $action = 'propose';
    $reason = '';
    if ($baseline > 0 && ($proposed > $baseline * SANITY_MULT || $proposed < $baseline / SANITY_MULT)) {
        $action = 'skipped_sanity';
        $reason = sprintf('%.0f%% swing exceeds sanity limit', $movePct);
    } elseif ($confidence === 'low') {
        $action = 'needs_review';
        $reason = sprintf('%d family, %d sales behind it', $blend['families'], $salesBacked);
        ++$gradedStats['review'];
    } elseif ($movePct > MAX_MOVE_PCT) {
        $direction = $proposed > $baseline ? 1 : -1;
        $proposed = round($baseline * (1 + $direction * MAX_MOVE_PCT / 100), 2);
        $action = 'clamped';
        $reason = sprintf('move %.1f%% clamped to %.0f%%', $movePct, MAX_MOVE_PCT);
    } elseif (abs($proposed - $baseline) < 0.01) {
        $action = 'unchanged';
        ++$gradedStats['unchanged'];
    }

    $appliedPrice = null;
    if (in_array($action, ['propose', 'clamped'], true)) {
        if ($apply) {
            $impact = round($proposed - $baseNow, 2);
            $db->update('product_attribute', ['price' => $impact], 'id_product_attribute = ' . $comboId);
            $db->update('product_attribute_shop', ['price' => $impact], 'id_product_attribute = ' . $comboId);
            $appliedPrice = $proposed;
            $action = 'applied';
            ++$gradedStats['applied'];
        } else {
            ++$gradedStats['proposed'];
        }
    }

    journal($runId, $productId, $comboRef, $baseline, $proposed, $appliedPrice,
        $marketCad, $blend['families'], $blend['spread_pct'], $confidence, $action, $reason, $sources);
}

line(sprintf('graded: applied %d | proposed %d | unchanged %d | review %d | held %d',
    $gradedStats['applied'], $gradedStats['proposed'], $gradedStats['unchanged'],
    $gradedStats['review'], $gradedStats['held']));

// ---------------------------------------------------------------------------
if ($apply) {
    Product::flushPriceCache();
    Tools::clearAllCache();
}

echo "\n";
line(sprintf(
    'applied %d | proposed %d | unchanged %d | review %d | clamped %d | floor %d | sanity %d | frozen %d | no data %d',
    $stats['applied'], $stats['proposed'], $stats['unchanged'], $stats['review'],
    $stats['clamped'], $stats['floor'], $stats['sanity'], $stats['frozen'], $stats['no_data']
));

if (!$apply) {
    echo "\n   Nothing was changed. Review with: make price-report\n";
}
