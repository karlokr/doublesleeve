# Pokémon catalog model

How DoubleSleeve is structured, and why. Read this before you list your first card
— a few of these decisions are painful to reverse once you have inventory.

## The core rule

**One product = one printing of one card.** Not one product per card name, and
not one product per physical copy.

"Charizard ex — Obsidian Flames 125/197" is a product. The Near Mint English copy
and the Lightly Played Japanese copy are *combinations* of that product, each with
its own SKU, price and stock count.

That gives you one product page per printing that accumulates its own SEO history,
reviews and internal links, while stock and pricing stay per-condition — which is
how card buyers actually shop.

## Attributes vs features

PrestaShop splits product data two ways, and putting something in the wrong bucket
is the most common modelling mistake.

| | Attributes | Features |
|---|---|---|
| Creates variants | Yes — own SKU, price, stock | No |
| Varies per copy | Yes | No |
| Here | Condition, Card Language, Finish | Rarity, Set, Artist, Regulation Mark, … |

The test: **does it change what you ship?** Two NM copies of the same printing are
interchangeable; a NM and an MP copy are not. Condition is an attribute. Rarity is
a property of the printing itself — every copy is Illustration Rare — so it is a
feature.

### Attributes (variant-generating)

- **Condition** — Near Mint, Lightly Played, Moderately Played, Heavily Played,
  Damaged. This is the TCGplayer/Cardmarket vocabulary; it is deliberately left in
  English on the French storefront because NM/LP/MP/HP is universal trade
  shorthand that French sellers use unchanged.
- **Card Language** — English, Japanese, French, German, Italian, Spanish, Korean,
  Traditional/Simplified Chinese, Portuguese.
- **Finish** — Normal, Holofoil, Reverse Holofoil, Poké Ball Pattern, Master Ball
  Pattern, Cosmos Holo, First Edition, Shadowless. The Poké Ball and Master Ball
  patterns matter for Scarlet & Violet era pulls; First Edition and Shadowless are
  what vintage pricing hinges on.

### Features (filterable metadata)

Rarity, Pokémon Type, Card Type, Stage, Regulation Mark, Format Legality, Grading
Company, Grade, Card Number, Artist, Certification Number.

Card Number, Artist and Certification Number are created with **no predefined
values** — they are effectively unbounded, so you set them per product as custom
values rather than bloating `feature_value` with thousands of rows.

Regulation Mark and Format Legality exist for competitive buyers, who shop by
"what can I play in Standard right now" far more than collectors do. They are
cheap to fill in and they sell decks.

## Don't create combinations you don't stock

3 attributes fully crossed is 5 × 10 × 8 = **400 combinations per card**. Generate
that blindly across a few thousand cards and the back office will crawl.

Create only the combinations you physically have. If you stock one NM English
Normal copy, that product has exactly one combination. Add others as you acquire
them. PrestaShop's product page handles a single combination fine.

## Grading is a copy-state axis, not a separate product

A PSA 10 Base Set Charizard is the same card as the raw one — same set, number
and artwork — in a different physical state, exactly as Lightly Played is. It
lives on the card's own listing as a combination of two attribute groups:

- **Grading**: Ungraded, PSA, BGS, CGC, TAG
- **Condition**: NM–DMG for ungraded copies; the company's own tier labels for
  slabs (`10 Gem Mint`, `10 Pristine`, `10 Black Label`, `9.5 Gem Mint`, then
  plain numerals below 9.5, where no company splits a numeral into tiers)

The (Grading, Condition) pair identifies a slab market — a BGS 10 Pristine, a
BGS 10 Black Label and a PSA 10 Gem Mint are three different prices wearing the
same numeral — and combinations constrain the pair, so a raw-only card never
offers "10 Gem Mint" and (PSA, 10 Black Label) cannot exist.

### Four graders, because we hold four frames

The shop carries **PSA, BGS, CGC and TAG only**. That is not a preference about
grading companies — a graded SKU's photograph is the card composited into *its*
grader's holder, and `src/ops/assets/slab-templates` holds frames for those four and
nobody else. A company in the vocabulary with no frame behind it is a filter a
shopper can select to be shown nothing, and an intake path that would produce a
slab photographed in the wrong holder.

ACE and SGC shipped in the original vocabulary and never had frames or stock;
`src/ops/migrations/retire-ungraded-graders.php` removes them and refuses to touch
any company that turns out to have combinations, so it cannot delete stock. To
carry a fifth company, add its frame first — the migration's list is the same
list the templates directory is.

### Grader and grade are one badge

They are rendered as a single chip, in the form the hobby writes: **`PSA 10`**,
`BGS 9.5`, `TAG 10 GEM MT`. Apart they read as two unrelated facts and cost
twice the room, when the grade *is* the condition and the company is what makes
the numeral mean anything.

The qualifier keeps its accepted short form — `Gem Mint` → `GEM MT` (which is
what PSA prints on the label itself), `Pristine` → `PRIS`, `Black Label` →
`BLK LBL`. An ungraded card is untouched: it has a condition and no grader, so
there is nothing to fold.


An earlier revision modelled each slab as its own product under a Graded
category tree, on the grounds that a slab is a serialised one-of-one with its
own photo and price. Every clause of that is true and describes a **SKU**, not a
separate product: combinations carry their own stock (quantity 1), price impact
and photo (the scan composited into the slab frame). What the split actually
cost was the card's identity — the same Charizard listed twice, and "everything
graded by PSA" unfilterable. Combination bloat never materialises because only
combinations that physically exist are created (previous section). Serial-level
identity (cert number, photos of the actual slab) belongs to the copies module,
which serialises raw stock the same way.

Graded prices come from sold auctions, not from a multiplier on the raw price —
see the graded pass in `src/ops/pricing/price-sync.php`.

## TCGplayer printing names are only unambiguous inside their group

Jungle and Team Rocket ran 1st Edition and Unlimited within one set, so
TCGplayer's SKUs there say "1st Edition Holofoil" and "Unlimited Holofoil" and
need nothing from us.

Base Set is the exception, because its 1st Edition run is **shadowless**.
TCGplayer therefore files 1st Edition and shadowless Unlimited together in group
1663 (`Base Set (Shadowless)`) and leaves group 604 (`Base Set`) holding the
shadowed Unlimited run on its own — with nothing to disambiguate against, those
SKUs are named bare `Holofoil` and `Normal`.

That is correct on TCGplayer, where the group is on screen, and wrong here. This
shop lists shadowed and shadowless side by side and sorts them together, so a
tile reading "Holofoil" next to one reading "1st Edition Holofoil" invites the
reader to assume the first is the earlier printing. It is the later one, and
worth an order of magnitude less.

`src/ops/lib/printing.php` holds the rename, keyed on the TCGplayer **group id**
(the set name is localised and shared across regions). `sku-rebuild` applies it
when building combinations, so a rebuild cannot undo it, and
`src/ops/migrations/base-set-unlimited.php` applies it to stock that already exists.

It is a **naming** change only. The price engine matches on `tcgplayer_subtype`
in `cc_price_source_map`, which keeps TCGplayer's own name, so renaming cannot
put a SKU on the wrong market price. The values it renames to already exist in
the vocabulary (the shadowless group uses them), so `align-tcgplayer.php` has
nothing new to prune.

### Edition and print run are different facts

They are stored in different places, and conflating them is the easy mistake:

| Fact | Lives on | Why |
|---|---|---|
| **Print Run** — Shadowless / Shadowed | the **product**, as a feature | Which pressing this listing is. The two runs are separate TCGplayer groups, so separate products. |
| **Edition** — 1st Edition / Unlimited | the **SKU**, as the Printing attribute | One shadowless Base Set product holds *both* a 1st Edition and an Unlimited combination, so it cannot be a product-level fact. |

`Print Run` also carries legacy `1st Edition` and `Unlimited` values that nothing
uses, left in place as unused vocabulary rather than deleted.

The stored value is `Shadowed`; the chip **reads "Not Shadowless"**, in every
place it appears and in both languages. Only one of the two runs is worth
identifying — a shopper hunting the expensive pressing is checking whether this
is it, and "Shadowed" answers a question they did not ask. One translation key
serves the listing tile, the product page and the cart line, so the fact cannot
end up worded two ways.

Both halves must be complete or a facet only works in one direction: only the
shadowless side was ever stamped, so a shopper could filter to Shadowless and had
no way to ask for the commoner, cheaper shadowed run sitting beside it.

`src/ops/audits/audit-editions.php` checks both — that every SKU in an edition-split
set states its edition, and that every product in a shadowless/shadowed pair
states its print run. It finds the pair from the category names, so a second
shadowless set would be covered without editing the audit.

## Sets are categories, not a feature

174 set categories across 17 series, from Base Set to the current Mega Evolution
series, generated from [pokemontcg.io](https://pokemontcg.io/).

Sets are browsable hierarchy — buyers navigate *Pokémon Singles → Scarlet & Violet
→ Prismatic Evolutions*. Duplicating that as a feature would mean maintaining the
set name in two places for zero gain; the faceted search module can filter on
category directly.

Set categories are suffixed with their code — `Prismatic Evolutions (PRE)` — because
several set names recur across Pokémon history (`Base` appears more than once).

New expansion? Add a row to `provisioning/data/pokemon-sets.csv` and re-run
`make provision`.

## Languages and currencies

Installed: **English (en-US)** and **Canadian French (fr-CA)**; **CAD** (default)
and **USD**.

You asked for `en-CA`, `fr-CA` and `en-US`. PrestaShop publishes no `en-CA`
translation pack for 9.1.x — only `en-US`, `en-GB` and `fr-CA` exist — so an
en-CA storefront would have to be hand-created and would then carry copy identical
to en-US. That means two English product descriptions to write and keep in sync,
and two English URLs per product competing for the same search terms.

What actually differs between a Toronto buyer and a Seattle buyer is **currency
and tax**, and neither is driven by storefront language:

- Currency is selected per visitor and drives displayed prices.
- Tax is computed from the **delivery address**, not the language.

If you still want a distinct en-CA storefront (its main real benefit is `YYYY-MM-DD`
dates and `$` rather than `CA$` for CAD), add it in *International → Localization →
Languages* with locale `en-CA` and a unique two-letter ISO code, then duplicate the
English translations. Set `LOCALES` in `src/ops/setup/setup.php` if you want it
provisioned automatically.

### Auto-switching currency by visitor location

Not native. PrestaShop's built-in Geolocation (*International → Geolocation*)
restricts countries and sets a default, but it does **not** map country → currency.
You need either a currency-by-geolocation module from the Addons marketplace, or a
small override that sets `$context->currency` from the detected country. Until then
customers switch currency manually via the currency selector.

Also set a real CAD↔USD conversion rate — both currencies are seeded at `1.0`.
*International → Currencies* has a rate updater; schedule it, or your USD prices
are wrong the moment the rate moves.

## Tax

- **Canada** — the installer applied the Canadian localization pack, so GST/HST/PST
  rules exist per province. Verify the rates in *International → Taxes → Tax Rules*
  before launch; they go stale.
- **United States** — sales tax depends on economic nexus per state and PrestaShop
  has no built-in nexus logic. If you cross a state's threshold you need TaxJar,
  Avalara, or an equivalent module. Selling into the US without this is a
  compliance problem, not a configuration gap.

## Listing a card — the short version

1. *Catalog → Products → Add new product*, type **Standard product**.
2. Name it `<Card Name> — <Set> <Number>`, e.g. `Charizard ex — Obsidian Flames 125/197`.
3. Assign it to the set category (`Pokémon Singles → Scarlet & Violet → Obsidian Flames (OBF)`).
4. *Combinations* → generate only the Condition × Card Language × Finish rows you
   actually hold. Set quantity and price impact per row.
5. *Details → Features* → fill Rarity, Card Number, Artist, Stage, Pokémon Type,
   Regulation Mark, Format Legality.
6. Photograph the **actual card** for anything above ~$20. Stock images cost you
   disputes on condition.

For bulk listing use *Advanced Parameters → Import* with a product CSV; that is the
only sane way past a few hundred cards.

## Next steps

- Replace the placeholder logo (*Design → Theme & Logo*).
- Enable and index faceted search (*Modules → Faceted search*) so Condition,
  Rarity and Regulation Mark become filters.
- Set up payment and carriers, and decide on tracked shipping thresholds — chargeback
  exposure on high-value singles is the main financial risk in this business.
- Real CAD↔USD rate updates (above).
- Before launch: turn off demo mode leftovers, set a real shop email, and run
  `make backup` on a schedule.


## Print runs: audited, not assumed

`make audit` runs two checks against live TCGplayer data (via the free TCGCSV
mirror) and both are worth re-running whenever the catalogue grows.

### Printing vocabulary — `audit-printings.php`

Compares the Printing values we sell per set against TCGplayer's own
`subTypeName` list for that group. A printing we sell that TCGplayer does not list
for that group means the card is filed under the wrong set — the expensive
failure, since a 1st Edition Base Set Charizard and an Unlimited one differ by
four figures. All 13 stocked sets currently pass.

The vocabulary is genuinely per-group, and not what you would guess:

| Group | TCGplayer printings |
|---|---|
| `Base Set` | Holofoil, Normal |
| `Base Set (Shadowless)` | 1st Edition, 1st Edition Holofoil, Unlimited, Unlimited Holofoil |
| `Jungle` / `Fossil` / `Team Rocket` | 1st Edition, 1st Edition Holofoil, Unlimited, Unlimited Holofoil |
| `Base Set 2` | Holofoil, Normal |
| modern (SV, SWSH) | Holofoil, Normal, Reverse Holofoil |

Note what this means: **`Base Set` carries no 1st Edition printing at all.** The
1st Edition and shadowless-Unlimited runs live in `Base Set (Shadowless)`; plain
`Base Set` is the shadowed Unlimited run.

### Parallel print runs — `audit-parallel-sets.php`

Distinguishes two shapes that look identical in a group list:

- **Parallel print run** — the same cards, same collector numbers, printed again.
- **Subset** — extra cards shipped alongside a set, with their own numbering
  (Trainer Gallery, Shiny Vault, Radiant Collection, Galarian Gallery).

Overlap is measured on **name + collector number**, not name alone. Name alone is
misleading: Base Set 2 reprinted 86% of Base Set's card *names* but renumbered
everything, so it scores 86% on names and **0%** on identity — correctly a
separate release rather than a reprint run.

Across all 217 TCGplayer groups, **`Base Set` ↔ `Base Set (Shadowless)` is the
only parallel print run** (92% shared identities). Every other candidate pair
scores 0%.

Because it is the only one and the price gap is several-fold, both listing tiles
and the product page carry an explicit **Shadowed** / **Shadowless** chip. The set
list driving those chips is derived (`printRunSets()`: any category ending
`(Shadowless)`, plus its unsuffixed sibling), so a future parallel run is picked
up without a code change.


### Editions — `audit-editions.php`

The second print-run distinction, and the one TCGplayer hides. Where
shadowed/shadowless is modelled as parallel **groups**, 1st Edition vs Unlimited
lives as SKU **subtypes inside one group** — so it never appears at set level and
is easy to leave unlabelled. The price gap is the same order of magnitude.

Scanning all 217 groups, **10 are edition-split** (both 1st Edition and Unlimited):

| Set | Stocked |
|---|---|
| Base Set (Shadowless) | yes |
| Jungle | yes |
| Fossil | yes |
| Team Rocket | yes |
| Gym Heroes, Gym Challenge | not yet |
| Neo Genesis, Neo Discovery, Neo Revelation, Neo Destiny | not yet |

Two more carry a single edition subtype (`WoTC Promo`, `Deck Exclusives`) and need
no disambiguation.

Note what is **not** on that list: plain `Base Set` has no 1st Edition printing at
all — its 1st Edition and shadowless-Unlimited runs live in `Base Set
(Shadowless)`. So Base Set tiles carry a Shadowed chip and no edition chip, which
is correct, not a gap.

**The bare `Normal` subtype in edition-split groups is sealed product**, not
singles — booster boxes, packs and theme decks, with the edition written into the
product name (`Jungle Booster Pack [1st Edition]`). Singles in these sets always
carry an edition-bearing printing. That is why rendering no chip for "Normal" is
safe.

Because that reasoning could silently stop holding, the storefront ships a
**canary**: a tile in an edition-split set that states neither edition renders a
red `Edition not set` chip instead of quietly showing nothing. It should never
appear; if it does, a SKU was built wrong in one of the sets where it costs the
most. The edition-split list is derived from the printings attached to our own
products (`editionSplitSets()`), joined through `tcg_group_category` so only real
set categories qualify — the `Singles` root aggregates every printing in the shop
and would otherwise look edition-split.

Current state: 12 1st Edition and 12 Unlimited chips each in Base Set
(Shadowless), Jungle and Team Rocket; zero canaries; zero edition chips on modern
sets.
