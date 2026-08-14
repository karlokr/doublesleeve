<?php
/**
 * Points PrestaShop's currency conversion rates at the Bank of Canada.
 *
 * Two separate faults made the storefront switcher do nothing:
 *
 *   1. PS_CURRENCY_FEATURE_ACTIVE was never set, so multi-currency was off and
 *      the switcher could not change anything.
 *   2. USD sat at conversion_rate 1.000000 - identical to CAD - so even with the
 *      feature on, every price rendered the same number with a different symbol.
 *
 * price-sync.php already pulls FXUSDCAD from the Bank of Canada Valet API and
 * caches it in price_fx, but it used that rate only to normalise SOURCE prices;
 * it never wrote it back to the currency table. This closes that gap.
 *
 * PrestaShop semantics: conversion_rate is "units of THIS currency per 1 unit of
 * the DEFAULT currency". The default is CAD and Valet quotes FXUSDCAD as CAD per
 * 1 USD, so the USD rate is its reciprocal - getting this backwards would price
 * every US order about 94% too high.
 *
 *   make currency-sync
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const VALET = 'https://www.bankofcanada.ca/valet/observations/';
/** Valet series per currency, quoted as CAD per 1 unit of that currency. */
const SERIES = ['USD' => 'FXUSDCAD', 'EUR' => 'FXEURCAD'];

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }

echo "\n\033[1m== Currency rates\033[0m\n";

$db = Db::getInstance();

/** Live rate from the Bank of Canada, or null if unreachable. */
function fetchRate(string $series): ?float
{
    $context = stream_context_create(['http' => [
        'timeout' => 20,
        'user_agent' => 'DoubleSleeve/1.0 (currency sync)',
    ]]);
    $body = @file_get_contents(VALET . $series . '/json?recent=1', false, $context);
    if ($body === false) {
        return null;
    }
    $observation = (json_decode($body, true)['observations'][0] ?? null);
    $value = $observation[$series]['v'] ?? null;

    return $value === null ? null : (float) $value;
}

/** Last rate price-sync cached, so a Valet outage does not break checkout. */
function cachedRate(string $pair): ?float
{
    $value = Db::getInstance()->getValue(
        'SELECT rate FROM ' . _DB_PREFIX_ . 'price_fx WHERE pair = "' . pSQL($pair) . '"'
    );

    return $value === false || $value === null ? null : (float) $value;
}

// ---------------------------------------------------------------------------
// 1. multi-currency has to be switched on at all
// ---------------------------------------------------------------------------
if (!Configuration::get('PS_CURRENCY_FEATURE_ACTIVE')) {
    Configuration::updateValue('PS_CURRENCY_FEATURE_ACTIVE', 1);
    line('PS_CURRENCY_FEATURE_ACTIVE enabled (was off - the switcher was inert)');
} else {
    line('PS_CURRENCY_FEATURE_ACTIVE already on');
}

// ---------------------------------------------------------------------------
// 2. rates, relative to the default currency
// ---------------------------------------------------------------------------
$defaultId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$default = new Currency($defaultId);
if (!Validate::isLoadedObject($default)) {
    warn('no default currency configured');
    exit(1);
}
$defaultIso = strtoupper((string) $default->iso_code);
line("default currency: $defaultIso");

if ($defaultIso !== 'CAD') {
    warn("this script assumes a CAD default; got $defaultIso - rates not touched");
    exit(1);
}

// The default currency is the unit of account and must be exactly 1.
if ((float) $default->conversion_rate !== 1.0) {
    $default->conversion_rate = 1.0;
    $default->update();
    line("$defaultIso conversion_rate reset to 1.000000");
}

$updated = 0;
foreach (Currency::getCurrencies(false, false) as $row) {
    $iso = strtoupper((string) $row['iso_code']);
    if ($iso === $defaultIso) {
        continue;
    }
    if (!isset(SERIES[$iso])) {
        warn("no Bank of Canada series for $iso - left at " . $row['conversion_rate']);
        continue;
    }

    $series = SERIES[$iso];
    $pair = $iso . 'CAD';
    $cadPerUnit = fetchRate($series);
    $source = 'Valet';
    if ($cadPerUnit === null) {
        $cadPerUnit = cachedRate($pair);
        $source = 'cached';
    }
    if ($cadPerUnit === null || $cadPerUnit <= 0) {
        warn("no rate for $pair (live or cached) - $iso left unchanged");
        continue;
    }

    // Valet gives CAD per 1 unit; PrestaShop wants units per 1 CAD.
    $rate = round(1 / $cadPerUnit, 6);

    $currency = new Currency((int) $row['id_currency']);
    if (!Validate::isLoadedObject($currency)) {
        continue;
    }
    $before = (float) $currency->conversion_rate;
    $currency->conversion_rate = $rate;
    $currency->update();
    ++$updated;

    line(sprintf(
        '%s: %s = %.4f CAD  ->  conversion_rate %.6f (was %.6f) [%s]',
        $iso, $iso, $cadPerUnit, $rate, $before, $source
    ));
}

line("$updated currency rate(s) updated");

Tools::clearAllCache();
line('caches cleared');
