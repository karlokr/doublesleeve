<?php
/**
 * Names a TCGplayer printing for OUR storefront.
 *
 * TCGplayer's printing name is only unambiguous inside its own group. Jungle and
 * Team Rocket ran 1st Edition and Unlimited within one set, so their SKUs say
 * "1st Edition Holofoil" and "Unlimited Holofoil" and need nothing from us.
 *
 * Base Set is the exception. Its 1st Edition print run is SHADOWLESS, so
 * TCGplayer files 1st Edition and shadowless Unlimited together in group 1663
 * ("Base Set (Shadowless)") and leaves group 604 ("Base Set") holding the
 * shadowed Unlimited run alone. With nothing to disambiguate against, those SKUs
 * are named bare "Holofoil" and "Normal".
 *
 * That is fine on TCGplayer, where the group is on screen, and wrong here: this
 * shop lists shadowed and shadowless side by side and sorts them together, so a
 * tile reading "Holofoil" next to one reading "1st Edition Holofoil" invites the
 * reader to assume the first is the earlier printing. It is the later one, and
 * worth an order of magnitude less.
 *
 * Naming only. The price engine matches on `tcgplayer_subtype` in
 * cc_price_source_map, which keeps TCGplayer's own name, so renaming here cannot
 * put a SKU on the wrong market price.
 */
declare(strict_types=1);

/**
 * TCGplayer group id => [their subtype name => ours].
 *
 * Keyed on the group rather than the set name because the name is localised and
 * two sets share it across regions.
 */
const PRINTING_GROUP_OVERRIDES = [
    // Base Set (shadowed) - the Unlimited run, and the only run in this group.
    604 => [
        'Holofoil' => 'Unlimited Holofoil',
        'Normal' => 'Unlimited',
    ],
];

/**
 * What to call a TCGplayer subtype for a given group.
 *
 * Returns the subtype unchanged for every group that has no override, which is
 * all but one of them.
 */
function printingName(int $groupId, string $subtype): string
{
    return PRINTING_GROUP_OVERRIDES[$groupId][$subtype] ?? $subtype;
}
