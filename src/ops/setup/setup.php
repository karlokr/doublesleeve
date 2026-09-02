<?php
/**
 * DoubleSleeve shop provisioning.
 *
 * Idempotent: safe to run repeatedly. Everything is looked up by name before
 * being created, so re-running only fills in what is missing.
 *
 *   make provision
 */
declare(strict_types=1);

const SHOP_NAME = 'DoubleSleeve';
const SPECIES_CSV = '/provisioning/data/pokemon-species.csv';

/**
 * Locales to run the storefront in. First entry becomes the shop default.
 *
 * Deliberately NOT en-CA: PrestaShop publishes no en-CA translation pack (only
 * en-US, en-GB and fr-CA exist for 9.1.x), and a second English storefront would
 * carry identical copy - doubling translation upkeep and creating duplicate-content
 * SEO risk for zero customer-visible gain. What actually differs between Canadian
 * and US buyers is currency and tax, and both are driven by currency + delivery
 * country, not by storefront language. See docs/pokemon-catalog.md.
 */
const LOCALES = ['en-US', 'fr-CA'];

/** Currencies to enable. First entry becomes the shop default. */
const CURRENCIES = ['CAD', 'USD'];

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AdminKernel.php';
require_once __DIR__ . '/../lib/region.php';

/**
 * Currency creation needs CLDR data, which only lives in the Symfony container.
 * A bare CLI script has no booted kernel, so boot one explicitly - going through
 * Tools::getContextLocale() instead fails with "Kernel Container is not available".
 */
$kernel = new AdminKernel('prod', false);
$kernel->boot();
$cldrRepository = $kernel->getContainer()->get('prestashop.core.localization.cldr.locale_repository');

// ---------------------------------------------------------------------------
// output helpers
// ---------------------------------------------------------------------------
$GLOBALS['warnings'] = [];

function step(string $s): void { echo "\n\033[1m== $s\033[0m\n"; }
function ok(string $s): void { echo "   + $s\n"; }
function skip(string $s): void { echo "   . $s (already present)\n"; }
function warn(string $s): void { $GLOBALS['warnings'][] = $s; echo "   ! $s\n"; }

/** Same string for every installed language. */
function everyLang(string $value): array
{
    $out = [];
    foreach (Language::getLanguages(false) as $lang) {
        $out[(int) $lang['id_lang']] = $value;
    }

    return $out;
}

/** Per-locale strings, falling back to the 'en' key for anything unmapped. */
function perLang(array $byLocale): array
{
    $out = [];
    foreach (Language::getLanguages(false) as $lang) {
        $locale = $lang['locale'] ?? '';
        $short = substr((string) $locale, 0, 2);
        $out[(int) $lang['id_lang']] = $byLocale[$locale] ?? $byLocale[$short] ?? $byLocale['en'];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// 1. shop identity
// ---------------------------------------------------------------------------
step('Shop identity');
Configuration::updateValue('PS_SHOP_NAME', SHOP_NAME);
ok('PS_SHOP_NAME = ' . SHOP_NAME);

/**
 * Production sits behind a TLS-terminating proxy, so the container itself only
 * ever speaks plain http. With PS_SSL_ENABLED off, PrestaShop decides the
 * canonical URL is http:// and 302s every https request down to it - and the
 * proxy 301s straight back up. That is an infinite redirect loop on the
 * homepage, ERR_TOO_MANY_REDIRECTS, with nothing in the logs but a wall of 302s.
 *
 * PrestaShop reads X-Forwarded-Proto in Tools::usingSecureMode(), so with these
 * on it correctly sees the original request as secure. Asserted here rather than
 * left to the installer's --ssl flag, which only applies on a fresh install.
 */
Configuration::updateValue('PS_SSL_ENABLED', 1);
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 1);
ok('https enforced (canonical URLs are https, no redirect loop behind Traefik)');

// Cards are sold as individual physical items; showing "in stock" counts and
// blocking oversell is the whole game for singles.
Configuration::updateValue('PS_STOCK_MANAGEMENT', 1);
Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 0); // deny orders when qty = 0
Configuration::updateValue('PS_DISPLAY_QTIES', 1);
Configuration::updateValue('PS_LAST_QTIES', 3);

/**
 * Ships as 1, which offers every attribute value regardless of whether a matching
 * SKU exists. On a card shop that means a buyer can pick "Normal / Heavily Played"
 * on a card stocked only as Normal/NM-LP-MP - a combination that was never in the
 * catalogue. With 0, only printing/condition pairs that exist AND have stock are
 * selectable.
 */
Configuration::updateValue('PS_DISP_UNAVAILABLE_ATTR', 0);
ok('stock: oversell denied, unavailable variants hidden, low-stock threshold 3');

// ---------------------------------------------------------------------------
// 2. languages
// ---------------------------------------------------------------------------
step('Languages');

function installedLocales(): array
{
    $map = [];
    foreach (Language::getLanguages(false) as $lang) {
        $map[$lang['locale']] = (int) $lang['id_lang'];
    }

    return $map;
}

foreach (LOCALES as $locale) {
    if (isset(installedLocales()[$locale])) {
        skip($locale);
        continue;
    }

    try {
        $result = Language::downloadAndInstallLanguagePack($locale, _PS_VERSION_, null, true);
        if ($result === true) {
            ok("installed $locale");
        } else {
            $msg = is_array($result) ? implode('; ', $result) : (string) $result;
            warn("could not install $locale: $msg");
        }
    } catch (Throwable $e) {
        warn("could not install $locale: " . $e->getMessage());
    }
}

$locales = installedLocales();
if (isset($locales[LOCALES[0]])) {
    Configuration::updateValue('PS_LANG_DEFAULT', $locales[LOCALES[0]]);
    ok('default language = ' . LOCALES[0]);
}

// ---------------------------------------------------------------------------
// 3. currencies
// ---------------------------------------------------------------------------
step('Currencies');

/**
 * refreshLocalizedCurrencyData() fills in localized names and symbols but leaves
 * numeric_iso_code and precision untouched - a currency created without this ends
 * up with precision 0, i.e. prices rendered with no cents.
 */
function applyCldrScalars(Currency $currency, $cldrRepository, string $iso): void
{
    $defaultLocale = (new Language((int) Configuration::get('PS_LANG_DEFAULT')))->getLocale();
    $cldrCurrency = $cldrRepository->getLocale($defaultLocale)->getCurrency($iso);
    if ($cldrCurrency === null) {
        return;
    }
    $currency->numeric_iso_code = $cldrCurrency->getNumericIsoCode();
    $currency->precision = (int) $cldrCurrency->getDecimalDigits();
}

foreach (CURRENCIES as $iso) {
    $id = (int) Currency::getIdByIsoCode($iso, 0, true);

    try {
        if ($id) {
            $currency = new Currency($id);
            $changed = false;

            if (!$currency->active) {
                $currency->active = true;
                $changed = true;
            }
            // Repair currencies that were created without CLDR scalars.
            if (empty($currency->numeric_iso_code) || (int) $currency->precision === 0) {
                applyCldrScalars($currency, $cldrRepository, $iso);
                $changed = true;
            }

            if ($changed) {
                $currency->save();
                ok("repaired/activated $iso (precision {$currency->precision})");
            } else {
                skip($iso);
            }
            continue;
        }

        $currency = new Currency();
        $currency->iso_code = $iso;
        $currency->active = true;
        $currency->conversion_rate = 1.0;
        $currency->unofficial = false;
        $currency->modified = false;
        // Localized names and symbols straight from CLDR - never hand-written.
        $currency->refreshLocalizedCurrencyData(Language::getLanguages(false), $cldrRepository);
        applyCldrScalars($currency, $cldrRepository, $iso);
        $currency->add();
        ok("created $iso (precision {$currency->precision})");
    } catch (Throwable $e) {
        warn("could not set up $iso: " . $e->getMessage());
    }
}

/**
 * Without this the storefront currency switcher renders but does nothing -
 * PrestaShop treats the shop as single-currency. Conversion rates themselves are
 * set by ops/pricing/currency-sync.php from the Bank of Canada.
 */
Configuration::updateValue('PS_CURRENCY_FEATURE_ACTIVE', 1);
ok('multi-currency enabled (PS_CURRENCY_FEATURE_ACTIVE)');

$defaultCurrency = (int) Currency::getIdByIsoCode(CURRENCIES[0], 0, true);
if ($defaultCurrency) {
    Configuration::updateValue('PS_CURRENCY_DEFAULT', $defaultCurrency);
    ok('default currency = ' . CURRENCIES[0]);
}

// ---------------------------------------------------------------------------
// 4. attributes  (variant-generating: these create combinations with their own
//    SKU, price and stock)
// ---------------------------------------------------------------------------
step('Attributes (product variants)');

/**
 * Condition uses the TCGplayer/Cardmarket grading vocabulary.
 *
 * The French labels keep the English abbreviation in brackets - "Quasi neuf
 * (NM)". NM/LP/MP/HP is universal trade shorthand that French-Canadian sellers do
 * use, but a customer-facing storefront in French should not simply be English;
 * carrying both means a collector still recognises the grade and a shopper who is
 * not one can still read it.
 *
 * `value_fr` is optional per group. Where it is absent the values are left in
 * English on purpose - see the note on Printing below.
 */
/**
 * DECLARATION ORDER IS THE SELECTOR ORDER. It is written to
 * attribute_group.position, and PrestaShop renders the variant selectors with
 * `ORDER BY ag.position ASC, a.position ASC, agl.name ASC`.
 *
 * That third term is why this has to be deliberate: when two groups share a
 * position the tie breaks on the group name IN THE LANGUAGE BEING BROWSED, so
 * "Card Language" sorted ahead of "Printing" in English while "Impression"
 * sorted ahead of "Langue de la carte" in French - the same product page showing
 * its selectors in a different order per locale.
 *
 * The order below narrows from the widest choice to the narrowest: the language
 * decides which market you are even buying in, the printing picks a variant
 * within it, and the condition describes the single copy in hand.
 */
const ATTRIBUTE_GROUPS = [
    /**
     * A fourth axis, exactly as TCGplayer models a SKU: product x Printing x
     * Condition x Language.
     *
     * A Western set is ONE release printed in several languages at the same
     * collector numbers, so an English and a French Charizard are the same card in
     * the same set - a variant, not a separate listing. PrestaShop carries a photo
     * and a price impact per COMBINATION, so each language keeps its own scan and
     * its own market price without splitting the product.
     */
    'Card Language' => [
        'fr' => 'Langue de la carte',
        'values' => ['English', 'Japanese', 'French', 'German', 'Italian', 'Spanish',
                     'Korean', 'Traditional Chinese', 'Simplified Chinese', 'Portuguese'],
        // Plain vocabulary, nothing hobby-specific - these simply translate.
        'value_fr' => [
            'English' => 'Anglais',
            'Japanese' => 'Japonais',
            'French' => 'Français',
            'German' => 'Allemand',
            'Italian' => 'Italien',
            'Spanish' => 'Espagnol',
            'Korean' => 'Coréen',
            'Traditional Chinese' => 'Chinois traditionnel',
            'Simplified Chinese' => 'Chinois simplifié',
            'Portuguese' => 'Portugais',
        ],
    ],
    /**
     * "Printing" is TCGplayer's own label for this axis (their API calls it
     * subTypeName); "Finish" is Magic/Scryfall vocabulary and means nothing to a
     * Pokémon buyer. These seven values are TCGplayer's complete vocabulary,
     * verified across 19 groups spanning every era.
     *
     * Shadowless is deliberately absent: TCGplayer models it as a separate SET
     * (`Base Set (Shadowless)`), not a printing - see docs/operations-pipeline.md.
     *
     * These DO translate. An earlier pass left them English on the grounds that
     * they are hobby terms and translating them would hurt market matching - but
     * every match we make (price sync, the printings endpoint, the audits) runs
     * against the ENGLISH name at id_lang 1. The French label is display-only, so
     * keeping it English cost a French shopper clarity and bought nothing.
     */
    'Printing' => [
        'fr' => 'Impression',
        'values' => ['Normal', 'Holofoil', 'Reverse Holofoil', '1st Edition',
                     '1st Edition Holofoil', 'Unlimited', 'Unlimited Holofoil'],
        'value_fr' => [
            'Normal' => 'Normale',
            'Holofoil' => 'Holo',
            // "Reverse" is what French-Canadian collectors actually say.
            'Reverse Holofoil' => 'Reverse Holo',
            '1st Edition' => '1re Édition',
            '1st Edition Holofoil' => '1re Édition Holo',
            'Unlimited' => 'Illimitée',
            'Unlimited Holofoil' => 'Illimitée Holo',
        ],
    ],
    /**
     * Grading is a copy-state axis, not a different product.
     *
     * A PSA 10 Base Set Charizard is the same card as the raw one - same set, same
     * number, same artwork - in a different physical state, exactly as Lightly
     * Played is. It used to be modelled as separate products under a Graded
     * category tree on the grounds that a slab is a serialised one-of-one with its
     * own photo and price; every clause of that is true and describes a SKU, not
     * an argument against one - combinations carry their own stock, price impact
     * and image.
     *
     * The axis is the copy's grading STATE, which is why "Ungraded" belongs on it
     * and why the group is not called "Grader": ungraded is a state a copy is in,
     * not a company that graded it - an earlier cut named the group Grader with a
     * "Raw" value, which was wrong from the first word.
     *
     * Two axes, not one "PSA 10" value: the company and the tier answer different
     * questions, and folding them together would make "everything graded by PSA"
     * unfilterable - which is exactly the control the nav's Graded menu needs.
     */
    'Grading' => [
        'fr' => 'Gradation',
        'values' => ['Ungraded', 'PSA', 'BGS', 'CGC', 'TAG', 'ACE', 'SGC'],
        'value_fr' => [
            // Agrees with "carte", the noun every copy actually is.
            'Ungraded' => 'Non gradée',
            'PSA' => 'PSA',
            'BGS' => 'BGS',
            'CGC' => 'CGC',
            'TAG' => 'TAG',
            'ACE' => 'ACE',
            'SGC' => 'SGC',
        ],
    ],
    /**
     * One axis for the copy's state, whatever scale measures it: the trade grades
     * NM-DMG describe an Ungraded copy, the tier values a slabbed one. Which scale
     * applies is decided by the Grading attribute beside it, and because the
     * product page only offers values that exist as combinations, a raw-only card
     * never shows "10 Gem Mint" and a slab never shows "Lightly Played".
     *
     * Tier values carry the company's own label wherever the same numeral names
     * MORE THAN ONE market: a BGS 10 Pristine (gold label), a BGS 10 with all-10
     * subgrades (Black Label) and a PSA 10 Gem Mint are three different prices
     * wearing the same "10". The (Grading, tier) PAIR is what identifies a slab -
     * combinations constrain it, so (PSA, 10 Black Label) can never exist as
     * stock. Below 9.5 no company splits a numeral into tiers, so plain numerals
     * suffice; company-specific epithets for those (PSA "MINT 9", CGC "NM/Mint 8")
     * are label decoration, not distinct markets.
     */
    'Condition' => [
        'fr' => 'État',
        'values' => ['Near Mint', 'Lightly Played', 'Moderately Played', 'Heavily Played', 'Damaged',
                     '10 Black Label', '10 Pristine', '10 Gem Mint', '9.5 Gem Mint',
                     '9', '8.5', '8', '7'],
        'value_fr' => [
            'Near Mint' => 'Quasi neuf (NM)',
            'Lightly Played' => 'Légèrement joué (LP)',
            'Moderately Played' => 'Modérément joué (MP)',
            'Heavily Played' => 'Très joué (HP)',
            'Damaged' => 'Endommagé (DMG)',
            // Tier names are the text printed on the physical slab label - proper
            // nouns, identical in every locale.
            '10 Black Label' => '10 Black Label',
            '10 Pristine' => '10 Pristine',
            '10 Gem Mint' => '10 Gem Mint',
            '9.5 Gem Mint' => '9.5 Gem Mint',
            '9' => '9', '8.5' => '8.5', '8' => '8', '7' => '7',
        ],
    ],
];

function findAttributeGroup(string $name): ?int
{
    foreach (AttributeGroup::getAttributesGroups((int) Configuration::get('PS_LANG_DEFAULT')) as $group) {
        if (strcasecmp($group['name'], $name) === 0) {
            return (int) $group['id_attribute_group'];
        }
    }

    return null;
}

/**
 * In-place renames from the first cut of the grading axis, BEFORE the ensure
 * loop: the loop finds groups and values by their default-language name, so a
 * renamed entry it cannot find would be recreated as a duplicate - the same
 * combinations pointing at an orphaned value while a fresh empty one takes its
 * place in the selector.
 *
 *   Grader -> Grading      "Raw" wasn't a grader; the axis is the grading STATE
 *   Raw -> Ungraded        and this is the state's honest name
 *   10 -> 10 Gem Mint      every existing 10 in stock is a PSA 10, whose tier
 *   9.5 -> 9.5 Gem Mint    is Gem Mint; likewise the CGC 9.5s
 *
 * Renaming keeps the attribute ids, so existing combinations follow along.
 */
// The group rename is a plain self-join update.
Db::getInstance()->execute(
    'UPDATE ' . _DB_PREFIX_ . 'attribute_group_lang agl
       JOIN ' . _DB_PREFIX_ . 'attribute_group_lang probe
            ON probe.id_attribute_group = agl.id_attribute_group
            AND probe.id_lang = 1 AND probe.name = "Grader"
      SET agl.name = "Grading", agl.public_name = "Grading"
      WHERE agl.id_lang = 1'
);

/**
 * Value renames in PHP, not SQL. A first attempt guarded the rename with a
 * NOT EXISTS over a derived table that referenced the outer query's alias -
 * which MariaDB rejects, Db::execute() swallowed, and the ensure loop below then
 * "helpfully" created the target values fresh and empty. So this handles both
 * states: target missing (plain rename) and target already created as an empty
 * duplicate (repoint the combinations, drop the duplicate, then rename).
 */
foreach ([
    ['Grading', 'Raw', 'Ungraded'],
    ['Condition', '10', '10 Gem Mint'],
    ['Condition', '9.5', '9.5 Gem Mint'],
] as [$inGroup, $from, $to]) {
    $lookup = static fn (string $name): int => (int) Db::getInstance()->getValue(
        'SELECT a.id_attribute FROM ' . _DB_PREFIX_ . 'attribute a
           JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = 1
           JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl
                ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = 1
                AND agl.name = "' . pSQL($inGroup) . '"
          WHERE al.name = "' . pSQL($name) . '"'
    );
    $fromId = $lookup($from);
    if (!$fromId) {
        continue;   // already migrated
    }
    $toId = $lookup($to);
    if ($toId) {
        // The empty duplicate exists: keep it, move the stock onto it.
        Db::getInstance()->execute(
            'UPDATE IGNORE ' . _DB_PREFIX_ . 'product_attribute_combination
                SET id_attribute = ' . $toId . ' WHERE id_attribute = ' . $fromId
        );
        (new ProductAttribute($fromId))->delete();
        ok("merged value: $inGroup / $from -> $to");
    } else {
        $attribute = new ProductAttribute($fromId);
        foreach (array_keys($attribute->name) as $idLang) {
            $attribute->name[$idLang] = $to;
        }
        $attribute->update();
        ok("renamed value: $inGroup / $from -> $to");
    }
}

$position = 0;
foreach (ATTRIBUTE_GROUPS as $groupName => $spec) {
    $groupId = findAttributeGroup($groupName);

    $label = perLang(['en' => $groupName, 'fr' => $spec['fr']]);

    if ($groupId === null) {
        $group = new AttributeGroup();
        $group->name = $label;
        $group->public_name = $label;
        $group->group_type = 'select';
        $group->position = $position;
        $group->add();
        $groupId = (int) $group->id;
        ok("group: $groupName");
    } else {
        // Names were only ever written at creation, so a group added before its
        // French label existed kept the English one forever - which is why the
        // French storefront showed "Printing" beside "État" and "Rareté".
        $group = new AttributeGroup($groupId);
        if (Validate::isLoadedObject($group)) {
            $group->name = $label;
            $group->public_name = $label;
            // Position was write-once, so groups added in different passes ended up
            // sharing one - and a shared position is what let the selector order
            // fall through to the localised name. Rewritten every run.
            $group->position = $position;
            $group->update();
            ok("group: $groupName (labels refreshed)");
        } else {
            skip("group: $groupName");
        }
    }
    ++$position;

    $existing = [];
    foreach (AttributeGroup::getAttributes((int) Configuration::get('PS_LANG_DEFAULT'), $groupId) as $attr) {
        $existing[strtolower($attr['name'])] = (int) $attr['id_attribute'];
    }

    $valuePosition = 0;
    foreach ($spec['values'] as $value) {
        // A group without value_fr keeps the English string in every locale.
        $label = isset($spec['value_fr'][$value])
            ? perLang(['en' => $value, 'fr' => $spec['value_fr'][$value]])
            : everyLang($value);

        if (isset($existing[strtolower($value)])) {
            // Refresh labels on values that already exist - they were created
            // with everyLang(), so the French storefront showed "Near Mint"
            // beside a facet headed "État".
            $attribute = new ProductAttribute((int) $existing[strtolower($value)]);
            if (Validate::isLoadedObject($attribute)) {
                $attribute->name = $label;
                $attribute->update();
            }
            ++$valuePosition;
            continue;
        }
        $attribute = new ProductAttribute();
        $attribute->id_attribute_group = $groupId;
        $attribute->name = $label;
        $attribute->position = $valuePosition++;
        $attribute->add();
        ok("  value: $groupName / $value");
    }
}

// ---------------------------------------------------------------------------
// 5. features  (filterable metadata, not variant-generating)
// ---------------------------------------------------------------------------
step('Features (card metadata)');

/**
 * Features with an empty value list are filled in per product as custom values -
 * card number, artist and cert number are effectively unbounded, so predefining
 * them would just bloat the feature_value table.
 */
/** BGS-style subgrades, shared by all four sub-scores. */
const SUBGRADES = ['10', '9.5', '9', '8.5', '8', '7.5', '7', '6.5', '6', '5', '4', '3', '2', '1'];

/** Pokémon TCG began in 1996 (JP) / 1999 (EN); cap well past today so it stays valid. */
const RELEASE_YEARS = [
    '1996', '1997', '1998', '1999', '2000', '2001', '2002', '2003', '2004', '2005', '2006',
    '2007', '2008', '2009', '2010', '2011', '2012', '2013', '2014', '2015', '2016', '2017',
    '2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025', '2026', '2027',
];

const FEATURES = [
    /**
     * Which catalogue a card comes from, derived from its set.
     *
     * A Japanese set is not a translation of a Western one: different card pool,
     * different numbering, different schedule. So region separates CATALOGUES,
     * while language varies within a Western set - the two are different questions
     * and both are worth filtering on.
     *
     * A feature rather than a variant because it is a property of the set the card
     * belongs to, so it can never differ between two SKUs of one product.
     */
    'Region' => [
        'fr' => 'Région',
        'values' => ['Western', 'Japanese', 'Chinese'],
        'value_fr' => [
            'Western' => 'Occidentale',
            'Japanese' => 'Japonaise',
            'Chinese' => 'Chinoise',
        ],
    ],
    'Rarity' => [
        'fr' => 'Rareté',
        'values' => [
            'Common', 'Uncommon', 'Rare', 'Double Rare', 'Ultra Rare', 'Illustration Rare',
            'Special Illustration Rare', 'Hyper Rare', 'ACE SPEC Rare', 'Shiny Rare',
            'Shiny Ultra Rare', 'Black White Rare', 'Rare Holo', 'Radiant Rare',
            'Amazing Rare', 'Secret Rare', 'Rainbow Rare', 'Full Art', 'Promo',
        ],
        /**
         * Official French rarity names, from Poképédia's "Rareté" article.
         *
         * French puts the qualifier BEFORE "rare" - "illustration rare",
         * "chromatique rare", "secrète rare", "holographique rare". An earlier pass
         * assumed French postposes adjectives the way it does elsewhere and wrote
         * every one of them backwards ("Rare Illustration"), which is not a
         * spelling variant: it is not what the cards or the community call them.
         *
         * Several are identical in French - Ultra Rare, Double Rare, Hyper Rare,
         * Rare, Promo - and that is the official name, not a missing translation.
         */
        'value_fr' => [
            'Common' => 'Commune',
            'Uncommon' => 'Peu commune',
            'Rare' => 'Rare',
            'Double Rare' => 'Double Rare',
            'Ultra Rare' => 'Ultra Rare',
            'Illustration Rare' => 'Illustration Rare',
            'Special Illustration Rare' => 'Illustration Spéciale Rare',
            'Hyper Rare' => 'Hyper Rare',
            // ACE SPEC is branded HIGH-TECH on French cards.
            'ACE SPEC Rare' => 'HIGH-TECH Rare',
            'Shiny Rare' => 'Chromatique Rare',
            'Shiny Ultra Rare' => 'Chromatique Ultra Rare',
            'Black White Rare' => 'Noir Blanc Rare',
            'Rare Holo' => 'Holographique Rare',
            // The seeded catalogue also contains the reversed spelling.
            'Holo Rare' => 'Holographique Rare',
            // Radiant Pokémon are "Pokémon Radieux"; the rarity is not in
            // Poképédia's list, so this follows the same qualifier-first pattern.
            'Radiant Rare' => 'Radieuse Rare',
            // Amazing Pokémon are "Pokémon Magnifique" - confirmed in the article.
            'Amazing Rare' => 'Magnifique Rare',
            'Secret Rare' => 'Secrète Rare',
            'Rainbow Rare' => 'Arc-en-ciel Rare',
            // Not one of Poképédia's rarity symbols - a TCGplayer marketplace
            // label, so this one is a translation rather than an official name.
            'Full Art' => 'Illustration Complète',
            'Promo' => 'Promo',
        ],
    ],
    'Pokemon Type' => [
        'fr' => 'Type de Pokémon',
        'values' => ['Grass', 'Fire', 'Water', 'Lightning', 'Psychic', 'Fighting',
                     'Darkness', 'Metal', 'Fairy', 'Dragon', 'Colorless'],
        // The official French energy-type names printed on French cards.
        'value_fr' => [
            'Grass' => 'Plante',
            'Fire' => 'Feu',
            'Water' => 'Eau',
            'Lightning' => 'Électrique',
            'Psychic' => 'Psy',
            'Fighting' => 'Combat',
            'Darkness' => 'Obscurité',
            'Metal' => 'Métal',
            'Fairy' => 'Fée',
            'Dragon' => 'Dragon',
            'Colorless' => 'Incolore',
        ],
    ],
    'Card Type' => [
        'fr' => 'Type de carte',
        'values' => ['Pokemon', 'Trainer - Item', 'Trainer - Supporter', 'Trainer - Stadium',
                     'Trainer - Tool', 'Energy - Basic', 'Energy - Special'],
        'value_fr' => [
            'Pokemon' => 'Pokémon',
            'Trainer - Item' => 'Dresseur - Objet',
            'Trainer - Supporter' => 'Dresseur - Supporter',
            'Trainer - Stadium' => 'Dresseur - Stade',
            'Trainer - Tool' => 'Dresseur - Outil',
            'Energy - Basic' => 'Énergie - de base',
            'Energy - Special' => 'Énergie - spéciale',
            // Seeded data also put bare energy types and "Trainer" in this
            // feature, so they are translated here too rather than left English.
            'Trainer' => 'Dresseur',
            'Basic Energy' => 'Énergie de base',
            'Grass' => 'Plante',
            'Fire' => 'Feu',
            'Water' => 'Eau',
            'Lightning' => 'Électrique',
            'Psychic' => 'Psy',
            'Fighting' => 'Combat',
            'Darkness' => 'Obscurité',
            'Metal' => 'Métal',
            'Dragon' => 'Dragon',
            'Colorless' => 'Incolore',
        ],
    ],
    'Stage' => [
        'fr' => 'Stade',
        'values' => ['Basic', 'Stage 1', 'Stage 2', 'V', 'VMAX', 'VSTAR', 'ex', 'GX',
                     'EX', 'Mega', 'BREAK', 'Radiant', 'Tera', 'Restored'],
        // V / VMAX / VSTAR / ex / GX / EX / BREAK are brand marks printed
        // identically on French cards, so only the real words translate.
        'value_fr' => [
            'Basic' => 'Base',
            'Stage 1' => 'Niveau 1',
            'Stage 2' => 'Niveau 2',
            'Mega' => 'Méga',
            'Radiant' => 'Radieux',
            'Tera' => 'Téra',
            'Restored' => 'Restauré',
        ],
    ],
    'Regulation Mark' => [
        'fr' => 'Marque de réglementation',
        'values' => ['D', 'E', 'F', 'G', 'H', 'I', 'None'],
        // Single letters are printed identically; only the absence needs a word.
        'value_fr' => ['None' => 'Aucune'],
    ],
    'Format Legality' => [
        'fr' => 'Légalité en tournoi',
        'values' => ['Standard', 'Expanded', 'Unlimited', 'Not Legal'],
        'value_fr' => [
            'Standard' => 'Standard',
            'Expanded' => 'Étendu',
            'Unlimited' => 'Illimité',
            'Not Legal' => 'Non autorisé',
        ],
    ],
    'Grading Company' => [
        'fr' => 'Société de gradation',
        'values' => ['Raw (Ungraded)', 'PSA', 'BGS', 'CGC', 'TAG', 'ACE'],
        // Grader names are companies; only the "not graded" case is a word.
        'value_fr' => ['Raw (Ungraded)' => 'Brute (non gradée)'],
    ],
    'Grade' => [
        'fr' => 'Note',
        'values' => ['10 Pristine', '10 Black Label', '10 Gem Mint', '9.5', '9', '8.5', '8',
                     '7', '6', '5', '4', '3', '2', '1', 'Authentic'],
    ],
    /**
     * The PRODUCT's print run - a fact about which pressing this listing is.
     *
     * "Shadowed" is the counterpart to "Shadowless" and the facet is useless
     * without it: only the shadowless side was ever stamped, so a shopper could
     * filter to Shadowless and had no way to ask for the commoner, cheaper run
     * they were probably looking at.
     *
     * NB: '1st Edition' and 'Unlimited' are legacy values here and are not used.
     * That distinction is per-SKU, not per-product - one shadowless Base Set
     * product holds both a 1st Edition and an Unlimited combination - so it lives
     * on the Printing ATTRIBUTE instead. Left in place rather than deleted; they
     * are unused vocabulary, not broken data.
     */
    'Print Run' => [
        'fr' => 'Tirage',
        'values' => ['1st Edition', 'Shadowless', 'Shadowed', 'Unlimited', 'Prerelease', 'Staff'],
        // "Sans ombre" is already what the storefront chip says, so the facet
        // must agree with it.
        'value_fr' => [
            '1st Edition' => '1re Édition',
            'Shadowless' => 'Sans ombre',
            'Shadowed' => 'Avec ombre',
            'Unlimited' => 'Illimitée',
            'Prerelease' => 'Avant-première',
            'Staff' => 'Staff',
        ],
    ],
    'Release Year' => ['fr' => 'Année de sortie', 'values' => RELEASE_YEARS],

    // --- graded slabs ------------------------------------------------------
    'Qualifier' => [
        'fr' => 'Qualificatif',
        // PSA/BGS qualifiers materially change a slab's value, so they must be
        // visible rather than buried in the description.
        'values' => ['None', 'OC (Off-Center)', 'ST (Stain)', 'MK (Print Mark)',
                     'MC (Miscut)', 'PD (Print Defect)'],
    ],
    'Label Type' => [
        'fr' => 'Type d\'étiquette',
        'values' => ['PSA Vintage', 'PSA Modern', 'PSA Lighthouse', 'BGS Standard',
                     'BGS Black Label', 'CGC Standard', 'CGC Perfect', 'TAG Standard'],
    ],
    'Subgrade - Centering' => ['fr' => 'Sous-note - Centrage', 'values' => SUBGRADES],
    'Subgrade - Corners' => ['fr' => 'Sous-note - Coins', 'values' => SUBGRADES],
    'Subgrade - Edges' => ['fr' => 'Sous-note - Bords', 'values' => SUBGRADES],
    'Subgrade - Surface' => ['fr' => 'Sous-note - Surface', 'values' => SUBGRADES],

    // --- sealed ------------------------------------------------------------
    'Sealed Product Type' => [
        'fr' => 'Type de produit scellé',
        'values' => ['Booster Box', 'Elite Trainer Box', 'Booster Bundle', 'Booster Pack',
                     'Collection Box', 'Premium Collection', 'Tin', 'Blister', 'Multi-Pack',
                     'Theme Deck', 'Battle Deck', 'Booster Case'],
    ],
    'Seal Status' => [
        'fr' => 'État du scellage',
        'values' => ['Factory Sealed', 'Reshrink Risk', 'Opened / Loose'],
    ],
    'Promo Included' => ['fr' => 'Promo incluse', 'values' => ['Yes', 'No']],
    'Pack Count' => ['fr' => 'Nombre de boosters', 'values' => []],
    'Cards Per Pack' => ['fr' => 'Cartes par booster', 'values' => []],

    // --- accessories -------------------------------------------------------
    'Brand' => [
        'fr' => 'Marque',
        'values' => ['Ultra Pro', 'Dragon Shield', 'Vault X', 'Ultimate Guard', 'KMC',
                     'Gamegenic', 'BCW', 'Pokémon Center', 'Cardboard Gold'],
    ],
    'Sleeve Size' => [
        'fr' => 'Taille de protège-cartes',
        'values' => ['Standard', 'Japanese / Small', 'Oversized'],
    ],
    'Material' => [
        'fr' => 'Matériau',
        'values' => ['Polypropylene', 'Rigid PVC', 'Acrylic', 'Leatherette', 'Cardboard'],
    ],
    'Capacity' => ['fr' => 'Capacité', 'values' => []],

    // --- unbounded, set per product as custom values ------------------------
    'Card Number' => ['fr' => 'Numéro de carte', 'values' => []],
    'Artist' => ['fr' => 'Illustrateur', 'values' => []],
    'Certification Number' => ['fr' => 'Numéro de certification', 'values' => []],
    'Grading Year' => ['fr' => 'Année de gradation', 'values' => []],
    'Pokedex Number' => ['fr' => 'Numéro du Pokédex', 'values' => []],
];

function findFeature(string $name): ?int
{
    foreach (Feature::getFeatures((int) Configuration::get('PS_LANG_DEFAULT')) as $feature) {
        if (strcasecmp($feature['name'], $name) === 0) {
            return (int) $feature['id_feature'];
        }
    }

    return null;
}

$position = 0;
foreach (FEATURES as $featureName => $spec) {
    $featureId = findFeature($featureName);

    if ($featureId === null) {
        $feature = new Feature();
        $feature->name = perLang(['en' => $featureName, 'fr' => $spec['fr']]);
        $feature->position = $position;
        $feature->add();
        $featureId = (int) $feature->id;
        ok("feature: $featureName");
    } else {
        skip("feature: $featureName");
    }
    ++$position;

    $existing = [];
    foreach (FeatureValue::getFeatureValuesWithLang((int) Configuration::get('PS_LANG_DEFAULT'), $featureId) as $row) {
        $existing[strtolower($row['value'])] = (int) $row['id_feature_value'];
    }

    /**
     * Drop exact duplicates before translating.
     *
     * Stage ended up with two "EX" rows, neither attached to a product. A
     * duplicate is not just untidy: getFeatureValuesWithLang() keys by value, so
     * only one of the pair ever gets its French label refreshed and the other
     * sits in the facet as a permanent English orphan.
     */
    foreach (Db::getInstance()->executeS(
        'SELECT MIN(fv.id_feature_value) AS keep_id, l.value, COUNT(*) AS n
           FROM ' . _DB_PREFIX_ . 'feature_value fv
           JOIN ' . _DB_PREFIX_ . 'feature_value_lang l
                ON l.id_feature_value = fv.id_feature_value AND l.id_lang = 1
          WHERE fv.id_feature = ' . (int) $featureId . '
          GROUP BY l.value HAVING n > 1'
    ) ?: [] as $dupe) {
        foreach (Db::getInstance()->executeS(
            'SELECT fv.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value fv
               JOIN ' . _DB_PREFIX_ . 'feature_value_lang l
                    ON l.id_feature_value = fv.id_feature_value AND l.id_lang = 1
              WHERE fv.id_feature = ' . (int) $featureId . '
                AND l.value = "' . pSQL((string) $dupe['value']) . '"
                AND fv.id_feature_value <> ' . (int) $dupe['keep_id']
        ) ?: [] as $extra) {
            $extraId = (int) $extra['id_feature_value'];
            // Only remove one nothing points at - never silently detach a product.
            if ((int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'feature_product
                  WHERE id_feature_value = ' . $extraId
            ) > 0) {
                warn("duplicate value in use, left alone: $featureName / " . $dupe['value']);
                continue;
            }
            (new FeatureValue($extraId))->delete();
            ok("  removed duplicate value: $featureName / " . $dupe['value']);
        }
    }

    // Translate every value we have a French term for - including ones seeded
    // into this feature by other scripts, not just the ones listed above.
    $translatable = array_unique(array_merge($spec['values'], array_keys($spec['value_fr'] ?? [])));

    foreach ($translatable as $value) {
        $label = isset($spec['value_fr'][$value])
            ? perLang(['en' => $value, 'fr' => $spec['value_fr'][$value]])
            : everyLang($value);

        if (isset($existing[strtolower($value)])) {
            // Refresh: these were created with everyLang(), so the French
            // storefront showed English options under a French facet heading.
            $featureValue = new FeatureValue((int) $existing[strtolower($value)]);
            if (Validate::isLoadedObject($featureValue)) {
                $featureValue->value = $label;
                $featureValue->update();
            }
            continue;
        }
        if (!in_array($value, $spec['values'], true)) {
            continue;   // translation-only entry for a value we do not seed
        }
        $featureValue = new FeatureValue();
        $featureValue->id_feature = $featureId;
        $featureValue->value = $label;
        $featureValue->custom = false;
        $featureValue->add();
    }
    if ($spec['values']) {
        ok("  " . count($spec['values']) . " values ensured for $featureName");
    }
}

// ---------------------------------------------------------------------------
// 5b. the Pokémon-name facet
// ---------------------------------------------------------------------------
// The single most-used way people shop singles ("show me Charizards"). 1,025
// values is a lot for one feature, but no other filter comes close in value, and
// the list is stable - it only grows when a new generation ships.
step('Pokémon facet');

$speciesId = findFeature('Pokemon');
$speciesLabel = perLang(['en' => 'Pokemon', 'fr' => 'Pokémon']);

if ($speciesId === null) {
    $feature = new Feature();
    $feature->name = $speciesLabel;
    $feature->position = count(FEATURES) + 1;
    $feature->add();
    $speciesId = (int) $feature->id;
    ok('feature: Pokemon');
} else {
    // Labels only ever applied at creation, so refresh them - see the same fix
    // for attribute groups above.
    $feature = new Feature($speciesId);
    if (Validate::isLoadedObject($feature)) {
        $feature->name = $speciesLabel;
        $feature->update();
    }
    ok('feature: Pokemon (labels refreshed)');
}

if (!is_readable(SPECIES_CSV)) {
    warn('species CSV not found at ' . SPECIES_CSV . ' - skipping Pokémon values');
} else {
    $existing = [];
    foreach (FeatureValue::getFeatureValuesWithLang((int) Configuration::get('PS_LANG_DEFAULT'), $speciesId) as $row) {
        $existing[strtolower($row['value'])] = (int) $row['id_feature_value'];
    }

    $handle = fopen(SPECIES_CSV, 'r');
    $speciesHeader = fgetcsv($handle);
    $added = 0;
    $localised = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $record = array_combine($speciesHeader, array_pad($row, count($speciesHeader), ''));
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        /**
         * Species names localise to the STOREFRONT language - this facet is a
         * search aid ("show me Charizards"), not the text printed on the card.
         * A French shopper looks for Dracaufeu. The card's own language is a
         * separate axis entirely; see the region model in the IA doc.
         */
        $french = trim((string) ($record['name_fr'] ?? ''));
        $label = $french !== ''
            ? perLang(['en' => $name, 'fr' => $french])
            : everyLang($name);

        if (isset($existing[strtolower($name)])) {
            $featureValue = new FeatureValue((int) $existing[strtolower($name)]);
            if (Validate::isLoadedObject($featureValue)) {
                $featureValue->value = $label;
                $featureValue->update();
                ++$localised;
            }
            continue;
        }

        $featureValue = new FeatureValue();
        $featureValue->id_feature = $speciesId;
        $featureValue->value = $label;
        $featureValue->custom = false;
        $featureValue->add();
        ++$added;
    }
    fclose($handle);
    ok("Pokémon values added: $added, localised: $localised");
}

// ---------------------------------------------------------------------------
// 6. categories
// ---------------------------------------------------------------------------
step('Category tree');

$rootCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
$defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

function findChildCategory(int $parentId, string $name): ?int
{
    $rows = Db::getInstance()->executeS(
        'SELECT c.id_category
           FROM ' . _DB_PREFIX_ . 'category c
           JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category
          WHERE c.id_parent = ' . (int) $parentId . '
            AND cl.name = "' . pSQL($name) . '"
          LIMIT 1'
    );

    return $rows ? (int) $rows[0]['id_category'] : null;
}

function ensureCategory(int $parentId, string $name, array $translations = []): int
{
    $existingId = findChildCategory($parentId, $name);
    if ($existingId !== null) {
        return $existingId;
    }

    $category = new Category();
    $category->id_parent = $parentId;
    $category->name = $translations
        ? perLang($translations + ['en' => $name])
        : everyLang($name);
    $category->active = true;

    $rewrite = [];
    foreach ($category->name as $idLang => $value) {
        $slug = Tools::str2url($value);
        // Numeric-only set names ("151") and symbol-heavy ones can slug to
        // nothing usable; fall back to something guaranteed unique.
        $rewrite[$idLang] = ($slug === null || $slug === '' || $slug === '-')
            ? 'cat-' . md5($value . $idLang)
            : $slug;
    }
    $category->link_rewrite = $rewrite;
    $category->add();

    return (int) $category->id;
}

/**
 * Move a category under a new parent, renaming it on the way.
 *
 * Used once, to migrate the original flat layout (Pokemon Singles / Sealed Product
 * / Graded Cards hanging off Home) into the game-first tree. Reparenting beats
 * delete-and-recreate: it keeps the 174 set category IDs and their URLs intact.
 */
function moveCategory(int $categoryId, int $newParentId, string $newName, array $translations = []): void
{
    $category = new Category($categoryId);
    if (!Validate::isLoadedObject($category)) {
        return;
    }
    $category->id_parent = $newParentId;
    $category->name = $translations
        ? perLang($translations + ['en' => $newName])
        : everyLang($newName);

    $rewrite = [];
    foreach ($category->name as $idLang => $value) {
        $rewrite[$idLang] = Tools::str2url($value);
    }
    $category->link_rewrite = $rewrite;
    $category->update();
    // update() fixes this category's depth; children need an explicit sweep.
    $category->recalculateLevelDepth($category->id);
}

// --- game-first top level --------------------------------------------------
// Games sit at the root so a second TCG is a config change, not a re-platform.
$pokemonId = ensureCategory($rootCategoryId, 'Pokémon');
ok('Pokémon');

/**
 * Accessories are not sold here.
 *
 * The shop carried a whole Supplies & Accessories branch - seven subcategories, a
 * facet template, a menu entry and a homepage tile - for a product line that does
 * not exist. Retired rather than hidden: an inactive branch still shows up in
 * exports, sitemaps and the admin category picker, and invites someone to
 * "re-enable" it later.
 */
foreach (['Supplies & Accessories', 'Accessories'] as $retired) {
    foreach ([$rootCategoryId, $pokemonId] as $parent) {
        $id = findChildCategory($parent, $retired);
        if ($id === null) {
            continue;
        }
        $category = new Category($id);
        if (Validate::isLoadedObject($category)) {
            // Category::delete() recurses, so the seven subcategories go with it.
            $category->delete();
            ok("retired: $retired");
        }
    }
}

// Migrate the legacy flat roots, if this shop still has them.
foreach ([
    ['Pokemon Singles', 'Singles', ['fr' => 'Cartes à l\'unité']],
    ['Sealed Product', 'Sealed', ['fr' => 'Produits scellés']],
    ['Graded Cards', 'Graded', ['fr' => 'Cartes gradées']],
] as [$legacyName, $newName, $translations]) {
    $legacyId = findChildCategory($rootCategoryId, $legacyName);
    if ($legacyId !== null) {
        moveCategory($legacyId, $pokemonId, $newName, $translations);
        ok("migrated: $legacyName -> Pokémon / $newName");
    }
}

$singlesId = ensureCategory($pokemonId, 'Singles', ['fr' => 'Cartes à l\'unité']);

/**
 * Print region sits between Singles and the eras. See lib/region.php for why it
 * earns a tree level here and not under Sealed or Graded.
 *
 * Only regions that actually have a set list are created. An empty-but-active
 * "Japanese" branch would still appear in the sitemap, the admin category picker
 * and the menu, which is the same trap the retired Accessories tree was - so the
 * Japanese node is created by the importer that brings its sets, not speculatively
 * here.
 */
$regionIds = [];
foreach (REGION_ORDER as $region) {
    $existing = findChildCategory($singlesId, $region);
    if ($existing !== null) {
        $regionIds[$region] = $existing;
    }
}
if ($regionIds === []) {
    // First run of this layout: Western is the catalogue we already have.
    $regionIds['Western'] = ensureCategory($singlesId, 'Western', regionCategoryLabel('Western'));
}
ok('Singles regions (' . implode(', ', array_keys($regionIds)) . ')');

/**
 * One-time: eras used to hang directly off Singles. Move any that still do under
 * Western, which is what every set in the catalogue was before Japanese existed.
 *
 * Reparented rather than recreated so the 217 set categories underneath keep their
 * IDs and their URLs. Idempotent by construction - after the first run Singles has
 * no children left except the region nodes.
 */
$strays = Db::getInstance()->executeS(
    'SELECT c.id_category, cl.name
       FROM ' . _DB_PREFIX_ . 'category c
       JOIN ' . _DB_PREFIX_ . 'category_lang cl
            ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) Configuration::get('PS_LANG_DEFAULT') . '
      WHERE c.id_parent = ' . (int) $singlesId
) ?: [];
$moved = 0;
foreach ($strays as $stray) {
    if (in_array((string) $stray['name'], REGION_ORDER, true)) {
        continue;
    }
    $era = new Category((int) $stray['id_category']);
    if (!Validate::isLoadedObject($era)) {
        continue;
    }
    // Name and slug are the era's own and stay untouched; only the parent moves.
    $era->id_parent = $regionIds['Western'];
    $era->update();
    $era->recalculateLevelDepth($era->id);
    ++$moved;
}
if ($moved > 0) {
    ok("moved $moved eras under Singles / Western");
}

// The set taxonomy is NOT built here. It is owned entirely by
// ops/catalog/sets-tcgplayer.php, which builds it from TCGplayer groups - the
// authoritative catalogue this shop is aligned to.
//
// This block used to create a second, parallel tree from pokemon-sets.csv
// (pokemontcg.io series -> "Base" > "Base (BS)"). Because `make provision` runs
// after `make sets-align`, it silently RESURRECTED the tree that sets-align had
// just deleted, leaving the storefront with two competing taxonomies for the same
// cards: an empty "Base > Base (BS)" beside the real "1999 > Base Set". The CSV is
// no longer read by anything - set logos come from tcgplayer-groups.csv instead.

// --- Sealed ----------------------------------------------------------------
// Organised by product type rather than by set: product types are a small stable
// list (a good tree), sets are an ever-growing one (a good facet). Buyers who know
// their set reach it by filtering.
$sealedId = ensureCategory($pokemonId, 'Sealed', ['fr' => 'Produits scellés']);
$sealedTypes = [
    'Booster Boxes' => 'Displays de boosters',
    'Elite Trainer Boxes' => 'Coffrets Dresseur d\'Élite',
    'Booster Bundles' => 'Lots de boosters',
    'Booster Packs' => 'Boosters',
    'Collection & Premium Boxes' => 'Coffrets collection et premium',
    'Tins' => 'Pokébox',
    'Blisters & Multi-Packs' => 'Blisters et multipacks',
    'Theme & Battle Decks' => 'Decks à thème et de combat',
];
foreach ($sealedTypes as $name => $fr) {
    ensureCategory($sealedId, $name, ['fr' => $fr]);
}
ok('Sealed (' . count($sealedTypes) . ' subcategories)');

// --- Graded ----------------------------------------------------------------
// Graded copies are COMBINATIONS on the card's own listing (see the Grader
// attribute group above), not products of their own, so the grading companies are
// facet values rather than categories. The Graded category survives only as the
// nav's entry point; the theme module points it at the graded filter.
$gradedId = ensureCategory($pokemonId, 'Graded', ['fr' => 'Cartes gradées']);
foreach (['PSA', 'BGS', 'CGC', 'TAG', 'ACE'] as $grader) {
    $graderCatId = findChildCategory($gradedId, $grader);
    if ($graderCatId !== null) {
        $held = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'category_product WHERE id_category = ' . $graderCatId
        );
        if ($held > 0) {
            // Products here mean the old model still has stock filed in it;
            // deleting would strand them, so leave it and say so.
            ok("kept grader category $grader ($held products still filed)");
            continue;
        }
        (new Category($graderCatId))->delete();
        ok("retired grader category: $grader");
    }
}

Category::regenerateEntireNtree();
(new Category($rootCategoryId))->recalculateLevelDepth($rootCategoryId);
ok('category nested tree regenerated');

/**
 * Every product filed anywhere under Pokémon is also associated with Pokémon.
 *
 * PrestaShop category pages list only DIRECTLY associated products - a parent does
 * not inherit its children's stock - so "all Pokémon products", the one view that
 * spans both regions and all three forms, listed nothing at all. The importers now
 * associate the full ancestor chain, but sealed and graded stock was created by a
 * seed that only ever runs once, so the existing rows need healing here.
 *
 * Written as a set operation against the nested tree rather than a product loop:
 * it is the same statement whether it repairs 54 rows or none.
 */
$pokemon = new Category($pokemonId);
Db::getInstance()->execute(
    'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'category_product (id_category, id_product, position)
     SELECT ' . (int) $pokemonId . ', cp.id_product, 0
       FROM ' . _DB_PREFIX_ . 'category_product cp
       JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = cp.id_category
      WHERE c.nleft > ' . (int) $pokemon->nleft . ' AND c.nright < ' . (int) $pokemon->nright . '
      GROUP BY cp.id_product'
);
ok('Pokémon holds ' . (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'category_product WHERE id_category = ' . (int) $pokemonId
) . ' products');

// ---------------------------------------------------------------------------
step('Done');
if ($GLOBALS['warnings']) {
    echo "\n\033[33mCompleted with " . count($GLOBALS['warnings']) . " warning(s):\033[0m\n";
    foreach ($GLOBALS['warnings'] as $warning) {
        echo "  - $warning\n";
    }
    exit(0);
}
echo "\nProvisioning completed cleanly.\n";
