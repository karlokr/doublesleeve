# DoubleSleeve — proposed information architecture

Covers the category tree, the attribute/feature model, and the search stack.

**Status: §2–§5 are built.** The tree, the full feature model, per-section facet
templates, the set directory page and Meilisearch are all live — see the README.
Still outstanding: the intent-based mega menu described in §4 (needs a child theme,
see the note there) and everything in §6.

Designing for the target of TCGplayer/Collectr-grade browsing, and for a catalogue
that outgrows Pokémon without a re-platform.

---

## 1. Governing principles

Four rules that decide where every piece of data goes. Most catalogue messes come
from violating one of them.

1. **The tree is for navigation and SEO. Facets are for finding.**
   A category earns its place only if someone would land on it from Google or click
   it deliberately. Everything else is a filter.
2. **The tree must be shallow and stable.** Depth is a UX tax and a migration
   liability. Facets are free to be numerous.
3. **Variants are only what changes what you ship.** Condition, language and finish
   change the physical item. Rarity does not.
4. **Game-agnostic concepts live above the game; game-specific ones live under it.**
   This is what makes adding Magic or Lorcana a config change rather than a rebuild.

---

## 2. Category tree

### Top level — game-first

```
Pokémon
Magic: The Gathering        (future)
Yu-Gi-Oh!                   (future)
One Piece                   (future)
Disney Lorcana              (future)
```

Games sit at the root rather than under a "Trading Cards" wrapper — the wrapper
buys one URL segment of tidiness and costs a click on every journey.

**There is no accessories branch.** The shop carried a `Supplies & Accessories`
root with seven subcategories, a facet template, a menu entry and a homepage tile,
for a product line that does not exist and is not planned. Retired rather than
hidden: an inactive branch still appears in exports, sitemaps and the admin
category picker, and invites someone to switch it back on.

### Under each game

```
Pokémon
├── Singles
│   └── <Era> / <Set>          ← SEO landing pages, NOT primary nav (see §4)
├── Graded
│   └── PSA / BGS / CGC / TAG / ACE
├── Sealed
│   └── Booster Boxes / Elite Trainer Boxes / Booster Bundles / Booster Packs
│       Collection & Premium Boxes / Tins / Blisters & Multi-Packs
│       Theme & Battle Decks
└── Accessories                (Pokémon-branded only)
```

This gives you the nesting you asked for, and each branch is a defensible landing
page: *"pokemon booster boxes"*, *"psa 10 pokemon"*, *"obsidian flames singles"* are
all real search queries.

### One script owns the set taxonomy

`provisioning/sets-tcgplayer.php` is the **only** thing allowed to create era or
set categories. `setup.php` used to build a second, parallel tree from
`pokemon-sets.csv` (pokemontcg.io series → `Base` > `Base (BS)`), and because
`make provision` runs *after* `make sets-align`, it silently resurrected the tree
that sets-align had just deleted. The storefront ended up with two competing
taxonomies for the same cards — an empty `Base > Base (BS)` beside the real
`Base / WotC > Base Set` — and every one of the 168 duplicate categories held zero
products.

This is the second time provisioning order caused a silent regression (the first
was `setup.php` recreating the retired `Finish` attribute group after alignment).
The rule that prevents it: **each taxonomy has exactly one owning script**, and
scripts that run later must never recreate what an earlier one retired.

### Why Sealed is by product type, not by set

Sealed shoppers split into "I want a Prismatic Evolutions ETB" (knows the set) and
"show me all booster boxes" (browsing). Product type is a small, stable, permanent
list — a good tree. Set is a large, ever-growing list — a good facet. The first
group is served by filtering; the second by the tree.

Singles inverts this: set *is* the natural landing page, because a set is a finite
collectible checklist in a way "booster boxes" never is.

### Migration cost is zero right now

Restructuring means new URLs. With no products and no inbound links, that cost is
nil today and rises permanently the moment you list inventory and get indexed.
**If this design is right, do it before the first product.**

---

## 3. The data model

Three tiers. The tier decides the PrestaShop mechanism.

### Tier 1 — Combination attributes (variant-generating)

Only these three. They change stock, SKU and price.

| Attribute | Values |
|---|---|
| Condition | Near Mint, Lightly Played, Moderately Played, Heavily Played, Damaged |
| Language | EN, JP, FR, DE, IT, ES, KO, ZH-T, ZH-S, PT |
| Finish | Normal, Holofoil, Reverse Holofoil, Poké Ball, Master Ball, Cosmos Holo, 1st Edition, Shadowless |

Everything else in this document is a **feature** — metadata that filters, but does
not multiply your SKUs.

### Tier 2 — Card identity (singles + graded)

Bold entries are **new** — not in what I built.

| Feature | Why it earns its place |
|---|---|
| **Pokémon / Character** | **The #1 way people shop singles. "Charizard", "Umbreon", "Pikachu".** |
| **National Pokédex №** | **Completionists and "show me all Eeveelutions" browsing.** |
| Set / Set Code / Card Number | Card identity; drives canonical SKU |
| Series (Era) | Coarse browsing, "vintage vs modern" |
| Rarity | Primary value proxy and top-3 filter |
| Pokémon Type | Grass/Fire/Water/… — deckbuilders and collectors both use it |
| Card Type | Pokémon / Trainer (Item, Supporter, Stadium, Tool) / Energy |
| Stage | Basic, Stage 1/2, V, VMAX, VSTAR, ex, GX, Mega, Tera, Radiant |
| Artist | A genuine collector axis — some buy by illustrator |
| Regulation Mark | Tournament legality; competitive buyers filter on it first |
| Format Legality | Standard / Expanded / Unlimited |
| **Release Year** | **Vintage/modern cuts and sorting** |
| **Print Run** | **1st Edition / Shadowless / Unlimited — drives vintage value** |

The Pokémon-name facet is ~1,000 values. That sounds heavy; it is the highest-value
filter in the shop and can be auto-populated from the same API that generated the
set list.

### Tier 3 — Slab data (graded only)

| Feature | Notes |
|---|---|
| Grading Company | PSA, BGS, CGC, TAG, ACE |
| Grade | 10 Pristine, 10 Black Label, 10 Gem Mint, 9.5, 9 … Authentic |
| Certification Number | Unique per slab; make it searchable |
| **Grading Year** | **Old vs new PSA labels price differently** |
| **Subgrades** | **BGS centering/corners/edges/surface** |
| **Qualifier** | **OC, ST, MK, MC, PD — materially changes value** |
| **Label Type** | **PSA vintage vs modern label** |

Each slab remains its own product, quantity 1, photographed individually.

### Tier 4 — Sealed

| Feature | Notes |
|---|---|
| Sealed Product Type | Kept as data, but NOT as a facet: the Sealed subcategories are the same seven values, so two filters described one axis one rail apart, and the feature's values were never translated |
| Set / Release Year | |
| **Print Region** | **US / EU / Asia-English / JP — different print runs, different prices** |
| **Pack Count / Cards per Pack** | **Lets buyers compare cost-per-pack across products** |
| **Promo Included** | **ETB promos drive purchase decisions** |
| **Seal Status** | **Factory Sealed / Reshrink Risk / Opened — the trust axis in sealed** |

### Tier 5 — Accessories

Brand, Type, Sleeve Size (Standard/Japanese/Oversized), Capacity, Colour, Material.

### Making it look clean

PrestaShop features are global — one shared list. What stops Singles filters from
polluting Accessories is **per-category filter templates in the faceted search
module**. That configuration, not the feature list, is what delivers "organised
extremely nicely". It is the step most shops skip.

### Canonical SKU

```
product   PKM-OBF-125              game-set-number
variant   PKM-OBF-125-EN-NM-RH     +language-condition-finish
```

Worth fixing now. It is what later makes price-sync, bulk import, buylist matching
and duplicate detection possible instead of painful.

---

## 4. Navigation — replacing the set drilldown

Your instinct is right: era → set in a sidebar is a checklist, not a shopping
experience. Replace the **Singles** mega-menu with intent-based entry points:

```
Pokémon Singles ▾

SHOP BY POKÉMON     SHOP BY RARITY        NEW & TRENDING      SHOP BY PRICE
Charizard           Illustration Rare      Latest set          Under $5
Pikachu             Special Illustration   Just listed         $5 – $25
Umbreon             Gold / Hyper Rare      Price drops         $25 – $100
Mewtwo              Full Art / Alt Art     Best sellers        $100+
Eevee               Vintage Holo
Lugia               ACE SPEC              → Browse all sets    → Deal of the day
Rayquaza
→ All Pokémon
```

Sets do not disappear — they move to a **set directory page** grouped by era with an
era jump-nav, linked from the main menu as *Browse Pokémon Sets*. That page is where
completionists go, and it beats a nested dropdown. **Built** — `/content/6-pokemon-sets`.

The directory renders a **uniform card grid**, not a bullet list: era sections, an
era-only jump nav, and one tile per set showing the official logo, the set name and
a live in-stock count.

Two constraints shaped it:

- **Every tile is exactly the same size.** Only 114 of 217 sets have artwork, so a
  layout that sized itself to its contents was guaranteed to look ragged. The art
  box is a fixed 16:10, the name reserves two lines whether it needs them or not,
  and the 103 sets without a logo get a typographic plate built from their
  TCGplayer abbreviation. Verified: all 217 tiles measure identically at both
  desktop (214×207) and 375px (170×180).

  `aspect-ratio` alone is **not** enough — it is a soft constraint, and sizing the
  image with `max-height` let tall logos (POP Series is 300×367) win against it,
  making those nine tiles 70px taller than the rest. The image must be `width:100%;
  height:100%; object-fit:contain` so blow-out is impossible.

- **Logos are trimmed before they are stored.** pokemontcg.io logos carry wide
  transparent margins, and the original renderer flattened them onto a fixed
  1200×500 canvas — baking ~2.4:1 letterboxing into every JPEG, which no CSS can
  undo. `seed-category-images.php` now computes the alpha bounding box and crops to
  it, so stored ratios range 0.82–5.14 instead of all being 2.40. Re-render with
  `--force` after changing the renderer; the script otherwise skips categories that
  already have an image.

- **The card background never changes on hover.** Logos are JPEGs flattened onto
  `--cc-surface`; any other backdrop turns their corners into visible rectangles.
  Hover reads through border colour and a lift instead.

- **Filters are injected by `theme.js`, not shipped in the page.** The purifier
  strips `<script>` from CMS content, so the name filter and the "In stock only"
  toggle are added client-side. Both work off classes already on the tiles
  (`.cc-set--stocked`), so filtering costs no round-trip. Emptied eras and their
  jump links hide themselves, and a no-match state explains which filter is
  responsible rather than leaving a blank page.

### Set names drop their release code

TCGplayer prefixes many groups with a release code — `SV04: Paradox Rift`. That is
catalogue plumbing, not a set name, and repeating it on every tile, breadcrumb and
category heading is noise. Categories now store **`Paradox Rift`**; the code lives
in `tcg_group_category.set_code` so it stays queryable.

Only the `CODE:` and `CODE - ` forms are stripped. The bare-space form must
survive — `SM Base Set` and `XY Base Set` would both collapse to `Base Set` and
collide with the 1999 set. Verified across all 217 groups: 68 renamed, **zero
collisions**.

### Set artwork

| Source | Sets | How |
|---|---|---|
| pokemontcg.io, matched in the groups CSV | 114 | `logo_url` already present |
| pokemontcg.io, recovered by name matching | 43 | `lib/logo.php` candidate spellings |
| Bulbagarden Archives backfill | 6 | `fetch-set-logos.php` → `data/set-logos-extra.csv` |
| Era fallback (dimmed) | 54 | the era's base-set logo |

pokemontcg.io holds **174 sets against TCGplayer's 217** — confirmed against the
live API, not just the CSV snapshot — and it lags new releases. `30th Celebration`
is a real September 2026 expansion with an official logo that simply is not in that
API yet, so `make set-logos` resolves the remainder through the Bulbagarden
Archives MediaWiki API and commits the result to `data/set-logos-extra.csv`. Normal
provisioning runs read that file and make no network calls.

**The last 54 have no official logo because they are not expansions.** They are
TCGplayer product-grouping buckets — McDonald's promo runs, trainer kits, jumbo
cards, World Championship decks. Searching the archives returns individual card
scans and sell sheets for these, never a set logo, because none was ever made.
Those inherit their era's logo at 55% toward the card surface, so it reads as a
generation marker rather than that set's own art.

Backfill matching requires the **longest word** of the set name to appear in the
candidate filename plus ≥50% coverage of the rest. Requiring every word rejected
almost everything real; requiring none returns confidently wrong logos, which is
worse than none at all.

Recovery matching is **era-scoped**: `XY Base Set` must resolve to the XY logo, not
the 1999 `Base Set` one. Candidate spellings cover the systematic differences —
pokemontcg.io drops era codes (`Ruby & Sapphire` vs `EX Ruby and Sapphire`), spells
promos as `Black Star Promos`, and uses accented `Pokémon GO`.
- **The CMS purifier is on.** `PS_USE_HTMLPURIFIER=1` strips `<script>`, HTML5
  sectioning elements (`<section>`, `<nav>`) and attributes like `loading="lazy"`,
  so the generated markup uses only `div/p/h2/a/img/span` and carries no inline
  styles — all styling is by class in the `cryptocards_theme` stylesheet.

**Menu depth is capped at 2.** `ps_mainmenu` renders
`Category::getNestedCategories()` in full and has no depth setting, so the Pokémon
panel was listing all 15 eras *and* all 217 sets — 260 links, of which 13 had
stock. `cryptocards_theme` now prunes the tree in `actionMainMenuModifier` (a
supported by-reference filter hook), which keeps Hummingbird unforked and keeps the
markup out of the page rather than hiding it with CSS.

Depth 2 lands exactly on the useful line:

| Branch | Menu shows | Menu stops before |
|---|---|---|
| Singles | 15 eras | the 217 sets |
| Sealed | 8 product types | — |
| Graded | 5 grading companies | — |

Sets are reached through the set directory, the faceted rail and search. That is
what TCGplayer and Troll and Toad do, and for the same reason: the set list grows
every few months and is mostly out of stock at any moment.

**Not yet built:** the four intent columns above. Those need curated content rather
than a category tree, so they still require a Hummingbird child theme overriding
`ps_mainmenu.tpl`. Today the menu is **two items**:

```
Pokémon ▾              Singles · Sealed · Graded · Sets
Browse Pokémon Sets
```

"Sets" inside the dropdown is a link to the set directory, not a category: the set
tree is 217 entries deep and belongs on a page rather than in a hover panel. It is
injected through `actionMainMenuModifier`, the same hook that prunes the menu.

`Browse Pokémon Sets` stays top-level only while Pokémon is the only game. When a
second TCG arrives it moves under its own game, because a set list means nothing
across games.

### Region has to enter the menu before Japanese stock does

The current dropdown lists 15 eras under `Singles`, all of them Western. Adding a
Japanese branch to the same column would put `Mega Evolution` next to `SV7a` with
nothing saying they are different catalogues, and the eras themselves collide —
Japanese has its own era names on its own schedule.

The menu's outer tabs are currently *product form* (Singles / Graded / Sealed /
Accessories). Region is a different question from form, and a shopper is
essentially never browsing two regions at once, so it wants to be the **first**
choice inside Singles rather than a fourth tab beside it:

```
Pokémon ▾
├── Singles ▸   Western ▸ <15 eras>
│                Japanese ▸ <JP eras>
├── Graded ▸ …
```

The set directory needs the matching switch — a segmented control above the era
list, not a merged wall of 671 sets. Both are cheap once the Japanese catalogue
exists and pointless before it, so they are sequenced with that import rather than
guessed at now.

Note what does **not** change: card language stays a property of the listing in
every region. Region decides which *set catalogue* a card belongs to; language
decides what is printed on it. A Japanese-language card from a Western set is a
different thing from a card in a Japanese set, and the model has to keep saying so.

### How era is derived

TCGplayer's group API exposes **no series or era field** — only `publishedOn`. Era
is therefore derived in `provisioning/lib/era.php`, from three signals in
descending order of reliability:

1. **TCGplayer's own set-code prefix** — `SV08: Surging Sparks`, `SWSH12: Silver
   Tempest`, `XY - Evolutions`, `EX Dragon`. Unambiguous where present. TCGplayer
   is inconsistent about the separator, so colon, dash and plain space are all
   accepted.
2. **pokemontcg.io's `series` field**, matched on a punctuation-insensitive key.
   The two catalogues disagree constantly on the same set — `HeartGold SoulSilver`
   vs `HeartGold & SoulSilver`, `Hidden Fates: Shiny Vault` vs `Hidden Fates Shiny
   Vault` — so keys collapse to alphanumerics. Sub-collections also fall back to
   their parent set.
3. **Release date**, bucketed by era window.

Coverage: **170 of 217 groups** land in a numbered era; the remaining 47 are
genuine promos (McDonald's sets, POP Series, Battle Academy, Trick or Trade) and
go to **Promos & Specials**.

pokemontcg.io's own `Other` series is deliberately *not* trusted — it mixes real
era sets (Legendary Collection, Southern Islands) with oddities, so those resolve
by release date instead.

**Release year is not the axis.** It was, briefly, and it was wrong: nobody shops
for "a 2016 card", they shop for XY. Year survives as product metadata and a
filter, not as a branch of the tree.

Sealed and Graded keep short type/company dropdowns; those lists are small enough
that a dropdown is genuinely the right control.

---

## 5. Search

This is the gap between a competent PrestaShop store and a Collectr-grade one, and
it is the one part that needs real engineering rather than configuration.

### Queries that must work

| Query | Requirement |
|---|---|
| `charizard` | Character facet |
| `charzard` | **Typo tolerance** |
| `charizard obsidian flames` | Multi-field relevance |
| `obf 125` | Set code + number lookup |
| `psa 10 charizard` | Cross-section search |
| `umbreon alt art` | **Synonyms** — "alt art" ≠ any official rarity name |
| `リザードン` | Multilingual synonyms (JP buyers) |

### Options, honestly

| Option | Typo tolerance | Instant | Effort | Cost |
|---|---|---|---|---|
| **ps_facetedsearch** (built in) | No | No | Config only | Free |
| **Meilisearch** container | Yes | Yes | Custom indexer | Free, self-hosted |
| **Elasticsearch** | Yes | Yes | High ops burden | Free, self-hosted |
| **Algolia** | Yes | Yes | Module exists | Paid, scales with usage |

**Recommendation: ps_facetedsearch now, Meilisearch next.** Faceted search gets you
correct filtering immediately and costs nothing. Meilisearch adds typo tolerance,
instant results and synonyms, drops into the existing Docker stack, and needs a
PrestaShop→Meilisearch indexer written — that is genuine build work, not a plugin
install. I would not pretend otherwise.

Sorts to expose everywhere: Relevance, Price ↑↓, Newest, Set release date, Best
selling, Pokédex № (singles only).

---

## 6. Beyond the ask — what actually makes it Collectr-level

The structure above is table stakes. These are the differentiators, roughly in
order of value:

1. **Condition-based automatic pricing.** Set one market price per card; derive
   variants by rule (NM 100%, LP 85%, MP 70%, HP 55%, DMG 40%). Without this,
   pricing five conditions across thousands of cards by hand is the thing that
   quietly kills the business.
2. **Market price sync.** Pull reference prices on a schedule, apply a margin rule,
   flag outliers for review. Never auto-publish blindly.
3. **Buylist.** Buying from customers is where TCG inventory actually comes from.
   Structurally it is a second catalogue with its own price list.
4. **Master-set tracker.** "You own 143/197 — here are the 54 you're missing, in
   stock now." This is Collectr's retention hook and it is directly monetisable.
5. **Pre-orders** for unreleased sets, with clear release-date messaging.
6. **Bulk listing pipeline.** CSV or scanner-driven. Past a few hundred cards,
   manual listing stops being viable.
7. **Per-slab photography** enforced above a price threshold — the cheapest
   chargeback insurance available.

---

## 7. Suggested build order

1. Restructure the tree (games → Pokémon → Singles/Graded/Sealed/Accessories) —
   **do this before listing anything**.
2. Add the missing features: Pokémon name, Pokédex №, release year, print run,
   slab and sealed tiers.
3. Configure per-category faceted search templates.
4. Rebuild the Singles mega menu + set directory page.
5. Canonical SKU scheme + bulk import.
6. Condition-based pricing rules.
7. Meilisearch.
8. Buylist / master-set tracker.


## Tile expansion must honour every SKU-selecting facet

Listing tiles are expanded client-side so each printing gets its own tile
(`expandPrintings()` + the `printings` front controller). That expansion runs
*after* PrestaShop has filtered the product list, so any facet it does not know
about gets silently undone.

That is what happened with Printing: filtering to "Unlimited Holofoil" returned
10 products, and the expansion then re-added every printing each of those products
has — putting 1st Edition tiles back on screen beside the Unlimited ones and
reporting "20 listings across 10 cards". The filter was applied and then reversed
one layer up, in the sets where the two runs differ several-fold in price.

The rule: **any facet that selects a SKU rather than a product must be forwarded
to the expansion endpoint.** Today that is `conditions` and `printings`; a facet
reader (`activeFacet(name)`) is shared between them so adding a third is one line
each side.

Facets that select a *product* need no forwarding — `Print Run` is a feature of
the set the product sits in, so PrestaShop's own filtering is sufficient and
expansion cannot reintroduce an excluded value.

Verified with the failing case and its neighbours:

| Filters | Tiles | Result |
|---|---|---|
| Printing: Unlimited Holofoil | 12 | all Unlimited Holofoil |
| + Print Run: Shadowless | 10 | all Unlimited Holofoil / Shadowless |
| none | 24 | both editions expanded, as intended |
| Condition: Lightly Played | 24 | all LP, no NM leakage |
| Condition + Printing, then re-sorted | 10 | both filters survive the AJAX re-render |


### Sorting has to be redone after expansion

PrestaShop sorts **products**; `expandPrintings()` then inserts each extra
printing next to the product it came from. Under "Price, high to low" that put a
$147 Unlimited Pikachu directly after its $599 1st Edition twin and ahead of a
$567 Magneton — every tile carries its own price, but only the parent's price had
been sorted on.

`resortByPrice()` re-orders the grid by each tile's own displayed price once
expansion finishes, and re-runs on the AJAX re-render. Details that matter:

- **Only price sorts are re-ordered.** For name or relevance, keeping a card's
  printings adjacent is the more useful arrangement — verified that a name sort
  still groups both editions of each card together.
- Tiles with an unreadable price keep their original position instead of being
  flung to one end.
- The price parser handles both `$1,234.50` and `1 234,50 $`, since fr-CA is a
  supported storefront locale — whichever separator appears last is the decimal.

Verified: 23 tiles descending and 20 ascending, **zero ordering violations**, and
still correct with a printing filter applied on top.

### Two pieces of chrome removed

- **The active-filters bar** renders whether or not anything is in it, leaving an
  empty rounded panel above the grid. `theme.js` sets `[hidden]` when it holds no
  controls, and a CSS `:empty` rule catches the pre-JS paint so it never flashes.
  It still appears normally once a filter is applied.
- **The results line** ("There are N listings across M cards") is gone. After
  expansion it could only restate what the grid already shows, and it pushed the
  first row further down the page. `updateListingCount()` was removed with it.


## Every facet is multi-select

Four facets shipped as single-select dropdowns — **Set, Pokemon, Release Year,
Artist** (plus the four graded Subgrades). A dropdown is single-select, and there
is no reason a shopper cannot want Charizard *or* Pikachu, two artists, or two
sets. All are checkboxes now; no `filter_type: 2` remains in any template.

The reason they were dropdowns is length: Pokemon has **155** values in stock,
Artist 74. Two things solve that without giving up multi-select:

- **The list scrolls** — capped at ~15.5rem so one long facet cannot push the rest
  of the rail off screen.
- **A type-to-filter box** is injected by `theme.js` on any facet with 10+ rows
  (Set, Pokemon, Rarity, Pokemon Type, Card Type, Artist). Typing "char" narrows
  155 Pokemon to Charizard, Charjabug, Charmander, Charmeleon.

**`filter_show_limit` is deliberately 0.** Setting it to 8 looked like the obvious
fix, but it truncates **server-side** and Hummingbird renders no "show more"
control — so 147 of the 155 Pokemon simply vanished from the DOM, which is worse
than the dropdown was. Length is a presentation problem and is handled in the
theme.

Two selector traps, both of which silently matched nothing or too much:

- Facet blocks are `section.accordion-item[data-name]`. Option **rows** also carry
  `.search-filters__item`, so a looser selector treats every row as its own facet.
- The scroll container is `ul.accordion-body` — the class is on the `<ul>` itself,
  so `.accordion-body > ul` matches nothing.

The rail also got a visual pass: uppercase section label, full-row hit targets
rather than 13px native boxes, custom accent checkboxes with a visible focus ring,
counts demoted to `--cc-text-faint`, and hover states on both headings and rows.
Verified at 1280px and 375px — no overflow, no low-contrast text, no light
backgrounds.

Note `Card Type` was already multi-select; it reads as a dropdown in a screenshot
only because the accordion is collapsed.


### Sidebar: one panel, and a selector that actually matched

The "two boxes" was self-inflicted. Hummingbird nests three containers:

```
#search_filters_wrapper.ps-facetedsearch    <- the column
  #_desktop_faceted
    #search-filters.search-filters          <- the panel
```

Styling more than one of them as a card produced a box inside a box. The wrapper
is now transparent and only `#search-filters` paints a surface; individual facets
are separated by a hairline rather than each becoming its own card.

Compounding it: the id is **`search-filters` with a hyphen**, but the CSS used
`#search_filters` with an underscore throughout — 19 selectors that matched
nothing. The rules only had any effect at all through their `.search-filters`
class variants.

### A facet that cannot express a choice is hidden

`Availability` renders whenever the stock filter is enabled, but with everything
in stock it offers exactly one option — "In stock" — which filters nothing. It is
now hidden when it has fewer than two options.

Scoped to `data-type="availability"` on purpose, **not** "any one-option facet":
a single-option attribute facet still narrows. `Print Run` offers only
"Shadowless", and ticking it takes 276 products to 24.

### Why Condition looked single-select

Every facet was already multi-select — verified at the URL level
(`Printing-Holofoil-Reverse Holofoil` → 188 of 276, `Release Year-1999-2024` →
144) and in the UI (two Condition boxes tick, both chips appear).

Condition *looks* inert because every card is stocked in all four conditions, so
filtering by it never changes the **product** count — it stays 276 either way.
What it does change is the tiles: the expansion endpoint narrows each tile to the
best condition within the filter, so badges and prices update. Real behaviour,
invisible in the count.


---

## Standing requirement: every string ships in fr-CA

The storefront runs **en-US (default) and fr-CA**. Every user-facing string must
carry a French translation at the moment it is written.

This is easy to miss because the module-injected strings **bypass PrestaShop's
translation system entirely**. `Media::addJsDef` payloads and literals inside
`theme.js` render identically in both locales, so the French storefront shows
French breadcrumbs and theme chrome wrapped around English stock boxes, photo
panels and chips.

Anything emitted from `cryptocards_theme`, `cryptocards_copies`, or generated into
CMS content by `pages.php` needs a per-locale variant. The pattern used here:
PHP resolves the active language and hands the frontend a `cryptocardsI18n`
dictionary; `theme.js` reads from it rather than holding literals.

**Check the French storefront before calling UI work done.** Testing only in
English hides this class of bug completely.

### Scope: labels AND values

Translating the label is half the job. A facet reading **`État`** whose options
read **`Near Mint` / `Lightly Played`** is still a broken French page. Coverage has
three layers and all three count:

| Layer | Example | Status |
|---|---|---|
| Module-injected UI strings | stock box, photo panel, chips | **done** — `cryptocardsI18n` |
| Attribute / feature **labels** | `Condition` → `État`, `Set` → `Extension` | **done** |
| Attribute / feature **values** | `Near Mint` → `Quasi neuf (NM)` | **done** |
| Set names | `Surging Sparks` → `Étincelles Déferlantes` | **done** — official, per language |
| Species names | `Charizard` → `Dracaufeu` | **done** — PokéAPI |
| Product titles | see *Listing names are derived* | **done** — composed per language |
| Search index | one Meilisearch index per language | **done** |
| Theme / module packs | `Skip to main content`, footer headings | **done** — `translations.php` |

Judgement is needed per vocabulary rather than translating everything mechanically:

- **Conditions translate** — `Near Mint` → `Quasi neuf`, `Lightly Played` →
  `Légèrement joué`. These are descriptions, and French buyers expect them.
- **Print runs and printings generally do not.** `Holofoil`, `1st Edition`,
  `Unlimited` are hobby terms used untranslated by French-Canadian collectors and
  by TCGplayer itself. Translating them would make listings harder to match against
  the market, not easier.
- **Set and species names DO translate, because they are officially localised.**
  An earlier version of this document said proper nouns never translate. That was
  wrong for the two that matter most: a Western set has an official French name and
  so does every species, and a French buyer searches for `Dracaufeu` in
  `Étincelles Déferlantes`. Artist names genuinely stay as printed.

The line to hold: translate what we wrote, adopt what the publisher officially
translated, and keep what the hobby wrote.

**Rarity is a case of getting that wrong in a way that looked right.** French puts
the qualifier BEFORE "rare" — `illustration rare`, `chromatique rare`,
`secrète rare`, `holographique rare` — and an earlier pass assumed French postposes
adjectives the way it usually does, writing every one of them backwards
(`Rare Illustration`). That is not a spelling variant; it is not what the cards say.
Corrected against Poképédia's *Rareté* article, which lists all fourteen current
symbols by name.

Several rarities are identical in French — `Ultra Rare`, `Double Rare`,
`Hyper Rare`, `Rare`, `Promo` — and that is the official name, not a missing
translation. `ACE SPEC` is branded `HIGH-TECH` on French cards, and Amazing
Pokémon are `Magnifique`.

### Navigation and tiles carry real artwork

A dropdown of era names on a dark rectangle tells a shopper nothing the label did
not. Every menu entry and homepage tile now sits on artwork **already in the
catalogue** (TCGplayer, pokemontcg.io, the Bulbagarden Archives), resolved by rule
rather than picked by hand:

| Entry | Artwork |
|---|---|
| an era | the logo of its most recent set that has one |
| anything else | the cover photo of the highest-value in-stock item under it |
| nothing of its own | whatever its parent resolved to |

Two refinements the first cut got wrong:

- **Cases sort last.** TCGplayer shoots multi-packs as a stack with a composited
  "x6" badge burnt into the photograph, and sorting on price alone put one at the
  top of the Sealed tile. A single sealed box is the cleaner picture and the more
  representative one, so any product whose name matches `Case|Bulk|Set of N` is
  ranked below the rest.
- **A hand-supplied photo wins over everything.** Drop a file at
  `provisioning/assets/<category-slug>.jpg` and that category uses it.

**Graded is composited.** It holds no stock, and there is no freely licensed
photograph of a graded slab anywhere usable (Wikimedia Commons returns 19th-century
seed catalogues; Openverse returns zero commercially-licensed results for every
phrasing). So instead of a photo of a graded card, the asset is a photo of an
**empty slab** with its card window left transparent, at
`provisioning/assets/graded-frame.webp`. The highest-value card in stock is
composited into the window and the frame is drawn back over the top, so its bevels
and grade label sit above the card exactly as they do in life.

That is the right shape for this asset: supplied once, true forever. The holder does
not change; the card inside it comes from stock and updates as stock does.

The window is **measured, not configured** — the longest run of transparent pixels
across the lower half, then down its centre. First-to-last transparent pixel does
not work: the frame's own outer edge is transparent too, so that returned the whole
image. On the supplied frame it measures 460×642 at aspect 0.717, against a real
card's 0.716.

### Era artwork is the era's base set

An era is named after its base set and branded by it, so the Scarlet & Violet logo
*is* the Scarlet & Violet Base Set logo. Three rules, each added because the previous
pick was visibly wrong:

| Rule | Without it |
|---|---|
| prefer a set whose name contains "Base Set" | newest-first gave Sword & Shield the "Crown Zenith" logo |
| shortest such name wins | "Base Set (Shadowless)" beat "Base Set" on document order |
| skip Promo and Energies subsets | they share or precede the base set's release date, so "Mega Evolution Promo" took ME01's place |

Where no set is literally a base set (Neo, Gym, EX, e-Card) the era's **first**
release is the one that defined its look, so the fallback is oldest, not newest.

### Only eras carry artwork in the menu

Sealed's product types and Graded's graders own no artwork, so they inherited the
same borrowed photo: one card repeated down a column, which reads as a rendering bug
rather than as decoration. Only era logos say something their label does not.

The logo takes the right 38% of the row and the label is padded out of that zone, so
`HeartGold & SoulSilver` wraps instead of running under the artwork. Reserving the
space is what makes near-full opacity safe: nothing sits behind text, so nothing has
to be faded to stay readable.

**Every logo is trimmed to its content box before scaling.** Set logos arrive with
wildly different amounts of empty canvas around them, and scaling the file scaled
that padding too, so "Mega Evolution" filled its row while "Gym" and "Platinum" sat
in the middle at a third the size. The logos were never different sizes; their
margins were. They also render onto a wide, short canvas matching the menu row —
one square-ish canvas for both menu and tiles is the other half of why the dropdown
looked ragged.

### Cards are cut; everything else has its background removed

`cutoutCard()` alphas only the CORNERS, which is right for a card because a scan is
the card edge to edge. A sealed product is photographed on a white sweep with space
around it, so putting it through the card cut left the sweep completely intact — the
booster box reached the homepage still sitting on a white rectangle. Products with a
`cc_card_identity` row get the card cut; everything else gets `cutoutLogo()`.

Rendered at 50% behind era links and 30% behind tiles, each masked away from where
its label sits. Three placements are deliberately excluded:

- **the top bar** — a background behind "Pokémon" in a dense header is noise;
- **the left rail** (Singles / Sealed / Graded) — those three own no artwork so they
  inherit a random card, and a 150px rail crops any image into an unrecognisable
  fragment. A background that cannot be identified is not decoration;
- **anything below 30% opacity** — the first cut sat at 14% on a near-black panel,
  which made the logos technically present and practically invisible.

Logos are `background-size: contain`, pushed right. `cover` zoomed into the middle
of each wordmark and cut off both ends, so "Legendary Treasures" rendered as a slab
of letter fragments.

### Links are not underlined, controls least of all

The neutral palette left links with no colour of their own, so the first fix was a
blanket `a:hover { text-decoration: underline }`. That put a rule directly under the
words in the nav bar, **on top of** the 2px indicator each menu item already animates
in, giving every item two underlines in two different styles, and it underlined the
wordmark.

Underlines are now scoped to prose (`.rich-text`, `.page-content`, product
descriptions), where a link sits inside a sentence with nothing else marking it out.
Navigation keeps its single animated indicator, and the wordmark slides its foil
sweep across the letters instead, the way tilting a holo card moves the shine.

The backgrounds are transparent PNGs, so the surface shows through and a repaint
cannot strand a baked-in backdrop, which is exactly what the set logos carried
until recently.

### Neutral chrome — the products are the colour

A page of Pokémon cards is saturated edge to edge, so the interface around them is
greyscale and gets out of the way. Saturation is spent only where it carries
meaning:

| Where | Colour | Why |
|---|---|---|
| Condition dot | green → lime → yellow → orange → **red** | an ordered scale, readable without parsing the grade name |
| Print run | amber | the largest price gap on the site |
| Stock state | green / amber / red | in, low, out |
| Everything else | greyscale | chrome |

The accent is **near-white**, not a hue: a white button on near-black is the
strongest affordance available without introducing a colour that competes with card
art. Two consequences that are easy to miss:

- anything sitting *on* the accent needs `--cc-accent-ink`, and Bootstrap's checked
  checkbox ships a white tick, so it has to be re-drawn dark or it vanishes;
- `filter: brightness(1.12)` on hover became a **no-op** — a near-white button
  cannot get brighter. Hover dims instead.

**Chip colours are pinned to their own tokens** (`--cc-chip-printing`,
`--cc-chip-run`) rather than derived from the accent. They encode facts about a
card, they were signed off separately from the chrome, and a repaint must not
quietly move them.

### One chip vocabulary, everywhere a card appears

Tiles, cart lines, checkout summary and the product page all render the same five
facts, and for a while each did it in its own visual language — the product page had
a parallel `.cc-setbadge` set at a different size and weight. A card looked like a
different kind of object depending on which page you were on.

There is now one vocabulary: `.cc-chip` plus a modifier per facet
(`--set`, `--run`, `--printing`, `--rarity`, `--language`, `--cond`), and the classes
are decided **server-side** in one place so the pages cannot drift apart again. The
old badge classes are gone rather than aliased.

**Matching class names was not enough.** 1st Edition and Unlimited each had their own
chip colour, and the tile script assigned them by testing the label against the
English literals `"1st edition"` and `"unlimited"` — a TRANSLATED string. So on the
French storefront neither ever matched and every printing stayed the generic violet,
while the product page, which makes the same decision server-side on the English
name, rendered 1st Edition amber. The two surfaces disagreed about the same card, and
English tiles disagreed with French ones.

Both problems had the same fix: **printing has one colour**. The amber was wrong on
its own terms too — it made 1st Edition look identical to Shadowless, which is an
unrelated fact about a different axis, and amber is reserved for the print run. The
edition survives as `data-edition="1st|unlimited"`, a marker rather than a colour, so
the "edition not set" check still works without being a visual decision.

| Chip | Colour | Meaning |
|---|---|---|
| Set | full-strength text | identity |
| Print run (Shadowless) | **amber** | the axis with the largest price gap |
| Printing | violet tint | which finish, 1st Ed and Unlimited included |
| Rarity | dim | reference data |
| Card language | text | identity |
| Condition | dim + coloured dot | grade, by attribute position |

### The badge line is the card's identity, including the selected printing

The product page badge line now reads the same order a cart line does:

```
BASE SET (SHADOWLESS) · SHADOWLESS · 1ST EDITION HOLOFOIL · HOLO RARE · ENGLISH
SET DE BASE (SANS OMBRE) · SANS OMBRE · 1RE ÉDITION HOLO · HOLOGRAPHIQUE RARE · ANGLAIS
```

Printing is the one badge that tracks the variant selector, so the line is rebuilt
on `updatedProduct` rather than rendered once. `Normal` still earns no badge — it is
the absence of a special printing, decided on the English name as everywhere else —
and 1st Edition keeps the loud treatment against Unlimited's quiet one, matching
shadowed/shadowless because it is the same kind of distinction at the same order of
price gap.

The selected printing is resolved by **attribute id**, read off the option values.
Every visible label on that page is translated, so matching one would have worked in
English and quietly stopped working in French — the fourth chance to repeat that
bug. A first cut still managed a version of it by scoping to `.product-variants`
when Hummingbird's wrapper is `product-variant`, singular; the selector now matches
on the input name (`group[...]`) and depends on no theme class at all.

### Quick view is a product page, so it gets the product page

The quick-view modal renders product markup from inside a LISTING, where none of the
product-page globals exist — so it was the last surface still showing a bare
Hummingbird product: no stock depth, no print-run badge, and **no way to choose your
exact card** on a shop built around exactly that.

The three renderers now take `(context, root)` instead of reaching for `document` and
`window`, and the same builders that fill the product page are exposed through a
`context` endpoint keyed by product id. The modal fetches once per product, caches,
and re-renders on its own variant changes — verified end to end: switching printing
and condition inside the modal swaps the chips, the stock box and the serial picker,
and picking a serial pins `cc_copy_uid` to **the modal's** add-to-cart form, not the
page's.

One payload, one set of builders: the modal and the page cannot describe a product
differently.

### One fact, one place

Rarity was being stated three times on a product page: as the badge, as
`description_short` body text, and in the data sheet. `description_short` held the
rarity and nothing else — a stopgap from before rarity had anywhere better to live —
so it is now cleared. Listing tiles never read the field.

The rule this follows is the same one that removed the duplicated stock count: a
page may state a fact prominently (badge) and reference it (data sheet), but it must
not assert it twice in body copy.

### The trap that keeps recurring: looking a thing up by its localised name

Four separate features broke the same way, always silently — a `WHERE name = "…"`
matching the CURRENT language returns zero rows the moment that label is
translated, and zero rows is not an error:

| Where | Matched | Broke |
|---|---|---|
| Tile expansion | `Printing` | no chips in French — the group is `Impression` |
| Search index | `Pokemon` feature | French docs indexed with no species |
| Search index | `Singles` category | Pokémon deep links came out relative |
| Search index | `$flat('Set')` on localised keys | French docs had no set and no rarity |
| Product badge | `/\(Shadowless\)$/` on the set name | the shadowless badge **never rendered in French** |
| Print-run map | `LIKE "%(Shadowless)"` on the set name | the map came back **empty in French**, so neither print-run chip rendered on any tile or cart line |

The last two are the expensive ones, and they are the same mistake in two methods:
the single distinction on this site worth four figures was silently absent from one
of its two storefronts, because in French the category is
`Set de Base (Sans ombre)` and neither pattern matches that.

The rule: **resolve identity at `id_lang = 1`, read display text in the target
language.** English names are the stable key; anything else is a label.

---

## Admin entry must auto-translate to every enabled language

Today a product typed into the back office in English leaves the French fields
empty, and the French storefront falls back to blank or to the English string
depending on the field. That is not workable once a human is entering stock daily.

**Requirement:** text entered in the default language (en-US) is automatically
propagated to every other enabled language (currently fr-CA) at save time, for the
translatable product fields — `name`, `description`, `description_short`,
`link_rewrite`, and the meta fields.

Design constraints this has to respect:

- **Never overwrite a human translation.** Auto-fill applies only when the target
  language field is empty, or when it still exactly matches the previous English
  value (i.e. it was auto-filled before and the English has since changed). A
  French field a person edited is authoritative and must survive re-saves.
- **`link_rewrite` is a URL, not prose.** It is slugified from the translated
  name, and changing it later breaks existing links — so it is generated once when
  empty and then left alone.
- **Machine translation is a fallback, not a promise.** Card descriptions are
  formulaic and templated, so most of the value comes from templating the French
  string from the same structured data rather than translating English prose. Where
  free text genuinely needs translating, the provider must be pluggable and
  failure must leave the English text in place rather than saving an empty field.
- **It runs on the admin save hook**, so it covers manual entry, CSV import and
  the seeding scripts through one path rather than three.

**Built** — `cryptocards_i18n`, hooking `actionProductSave`. Provenance lives in
`cc_i18n_autofill`, which records both the `source` text and what was actually
`written` into the target.

Recording both matters. A first cut stored only the source, and the overwrite
check asked "has the English changed since we auto-filled?". That marked a
**human-translated** field as stale the moment the English was edited, and
overwrote it. The check now asks "is the target still exactly what we wrote?" —
if not, a person owns that field and it is never touched again.

Verified behaviour:

| Scenario | Result |
|---|---|
| French empty, English saved | filled |
| French hand-edited, then English changed | **hand edit survives** |
| French still auto-filled, English changed | refreshed |
| `link_rewrite` already set, English renamed | unchanged |

`translate()` is a seam, not a service: it dispatches
`actionCryptocardsTranslate` and falls back to the source text. Card copy here is
templated from structured data, so generating French from the same data beats
machine-translating English prose. A provider failure leaves the English text in
place — a missing translation is a nuisance, a blank product name is a broken
page.

Seeding scripts still write both languages explicitly, which is why `setup.php`
uses `perLang()` rather than `everyLang()` for anything a customer reads.

**Card names are the one field auto-fill must not touch.** A card title is not
prose an admin wrote, it is a composition of matched facts (see *Listing names are
derived, never authored*), so translating the English string would be translating a
derived value. For any product with a row in `cc_card_identity`, the save hook
**re-derives** `name` and `link_rewrite` from the atoms instead of copying English
across — which also means a hand-edited card title does not survive, on purpose.


### The cart states the card, not just the price

The cart line rendered `Impression: Holo` and `État: Quasi neuf (NM)` as two rows of
label/value text and nothing else — so the page where a four-figure purchase gets
confirmed said **less about the card than the tile the buyer clicked to reach it**.
Rarity, print run and card language were all absent, and print run is the one that
moves the price most.

Each line now carries the same chips a tile does, in the same order and the same
colours: condition, printing, print run, rarity, card language. The title above them
already supplies set, collector number and the `[EN]` tag, so those are not
repeated; the original label/value rows are removed rather than left to say
condition twice.

The chips are composed **server-side** and handed over ready to render. Deriving
them from the rendered labels was not an option: a condition chip's colour comes
from the attribute's POSITION — best grade green, worst red — and no translated
string carries that.

The checkout summary uses entirely different markup with no data attributes, so the
combination id is read out of the product URL, whose first path segment is
`{id_product}-{id_product_attribute}-{slug}`.

### Listing tiles: fix the frame, not the contents

Chips looked misaligned on mobile, but the chips were never the cause. Measuring
two tiles in the same row on a real device (412×915, DPR 3.5):

| | tile A | tile B |
|---|---|---|
| image frame | 215px | **226px** |
| chip row | 84px | 80px |
| title top | 810 | **817** |

`.product-miniature__image-container` used `min-height`, so each frame grew to
whatever its own scan rendered — card scans vary in aspect — and every element
below inherited the offset. It is now a **fixed** `height` (300px desktop, 215px
mobile).

Two smaller contributors, both fixed:

- **Chip height is pinned** (`height: 1.45rem`, zero vertical padding). A chip
  whose label exactly filled the column (`1st Edition Holofoil` at 142px in a
  142px column) rendered 25px against a shorter chip's 23px.
- **The chip row reserves three lines** at every breakpoint. Reserving two was
  enough on mobile but not desktop, where a long printing label wrapped to a third
  line and re-introduced the drift.

Verified zero misalignments across title, price and add-to-cart on Base Set
(Shadowless), Base / WotC and Surging Sparks — including tiles with 1, 2 and 3
chips — at 1280px, at 412px, and on the French storefront.

Images also went from a 210px frame to 300px (+43%), with `default_xl` (400w) and
`medium_default` (452w) added to the srcset so high-DPI screens have something
sharper than the 336w cap Hummingbird ships.


---

## Card language and region — the model

Two independent axes get confused constantly, so they are named separately here:

- **Storefront language** — what the UI is written in (en-US, fr-CA). A shopper's
  choice.
- **Card language** — the language physically printed on the card. A property of
  the product.

A francophone in Montreal may well buy a Japanese card. Neither axis constrains
the other.

### Region is a property of the SET, not a variant of a card

This is the decision everything else follows from, and it is what TCGplayer does:
it runs **two separate categories** — `3 Pokemon` (Western, 217 groups) and
`85 Pokemon Japan` (**454 groups**, back to 1996). Japanese releases are not
translations of Western ones; they are different sets, different card pools,
different numbering, released on a different schedule. Modelling Japanese as a
"language variant" of an English card would be wrong at the identity level, not
just cosmetically.

| Region | Sets | Card languages within a set | Source |
|---|---|---|---|
| **Western** | shared across EN/FR/DE/IT/ES/PT | a real SKU axis — same set, same collector number | TCGplayer cat 3 |
| **Japanese** | its own catalogue | JA only | TCGplayer cat 85 |
| **Chinese** | its own catalogue | ZH only | **no TCGplayer source** |

So:

- **Within Western**, one *set* serves every language. `Surging Sparks 245/191`
  exists in English and French at the same collector number, in the same set.
- **Across regions**, not even the set is shared. A Japanese card belongs to a
  Japanese set and is a separate product.

### Card language is a property of the LISTING, not a variant axis

Card language was originally an attribute group — a fourth axis on the SKU
alongside Printing and Condition, mirroring TCGplayer's SKU tuple
(product × printing × condition × language). That is wrong for this store, and it
is now a **product-level feature**.

The deciding argument is that a language is not a variant of a card the way a
condition is. It changes:

- **the photograph** — the card face is physically different text, and this store
  serialises copies with real photos of the actual item. One product holding an
  English photo and French SKUs would show the buyer the wrong card;
- **the price** — a French Base Set Charizard tracks a different market from the
  English one, by a large multiple, so they cannot share a price row;
- **the identity in search** — "Dracaufeu français" has to land on the French
  listing, not on an English page with a dropdown.

Condition and Printing are genuine variants: the same physical print run, graded
or finished differently. Language is a different print run.

The practical consequence is that **the listing name can always carry its
language**, which is the requirement below. With language as a SKU axis it
provably cannot: one product with English and French SKUs has exactly one name and
no correct language to put in it.

TCGplayer is not a counter-example. They keep language in the SKU because they are
a *marketplace* — the product is the card and each seller's listing hangs off it.
For a single-seller store the listing is the sellable unit, which is our product.
They also, tellingly, split Japanese into a separate catalogue rather than making
it a language of the English card.

Combinations are therefore `Printing × Condition`, which is exactly what the tile
expansion endpoint already assumed.

### Listing names are derived, never authored

An admin never types a card title. The name is a composition of facts that all
come from the matched card, so any hand-edit is drift by definition and gets
overwritten on the next save.

The grammar, in every storefront language:

```
<card name> — <set name> <collector number> [<CARD LANGUAGE>]

Charizard — Base Set 004/102 [EN]          en-US storefront
Dracaufeu — Set de Base 004/102 [EN]       fr-CA storefront, same English card
Dracaufeu — Set de Base 004/102 [FR]       fr-CA storefront, French card
```

Each part localises independently, and each has exactly one source:

| Part | Source | Localised? |
|---|---|---|
| Card name | TCGplayer product name, species substituted | yes — species and qualifiers |
| Set name | the set category | yes — official per-language name |
| Collector number | TCGplayer `Number`, verbatim | no |
| Card language | the Card Language feature, as a trade code | no — the code IS the point |

Notes on the parts:

- **The species and the variant qualifier are translated inside the card name.**
  `Charizard VSTAR` becomes `Dracaufeu VSTAR`: `VSTAR`, `ex`, `V`, `GX` are brand
  marks printed identically on French cards. The parenthetical qualifier is a
  separate, closed vocabulary and it does translate —
  `Umbreon VMAX (Alternate Art Secret)` is
  `Noctali VMAX (Illustration Alternative Secrète)`, and that qualifier is often
  the single most valuable thing about the listing. Trainers and Energy have no
  species and stay as printed, which is correct — `Professor's Research` has an
  official French name but we do not have a source for it, and inventing one would
  be worse than leaving it.
- **The description is templated, not translated.** It is four substitutions into
  a fixed sentence, so each language gets its own template rather than a machine
  translation of the English one.
- **Square brackets, not parentheses.** Both halves of the title already spend
  parentheses on their own meaning — `Base Set (Shadowless)`, `Unown (A)` — so a
  parenthesised tag would read as part of the name in front of it, and
  `Set de Base (Sans ombre) 004/102 (EN)` is genuinely hard to parse.
- **The language tag is always present, including `[EN]`.** The trade convention
  is to mark only non-English and leave English implicit, which works when a shop
  is English by default. This one is not: it runs in two storefront languages and
  intends to sell in several card languages, so an untagged title would be
  genuinely ambiguous — and worse, ambiguous only *sometimes*, which is harder to
  read than a tag that is always there.
- **The storefront language never changes the tag.** `[EN]` on the French
  storefront means the card is English, not that the page is.
- **Trade codes, not ISO 639-1.** Japanese is `JP` and Korean is `KR`, because that
  is what collectors write; `[JA]` reads as a typo to the people buying. Chinese
  spells out the script — `ZH-T` / `ZH-S` — since that, not the language, is the
  distinction that matters on a card.

The atoms are persisted per product in `cc_card_identity`, so a name can be
re-derived at admin save time without a network call.

### Taxonomy

Regions sit alongside each other rather than nesting under a shared parent, so the
Western path — the overwhelming majority of stock — stays as shallow as it is now:

```
Pokémon
├── Singles                 (Western: the default, unprefixed)
│   └── <Era> → <Set>
├── Japanese Singles
│   └── <Era> → <Set>
└── Chinese Singles
    └── <Set>
```

"Browse Pokémon Sets" gains a region switch. Mixing 217 Western and 454 Japanese
sets into one alphabetical wall would make both harder to use, and a buyer is
essentially never shopping both at once.

### Chinese — deferred

TCGplayer catalogues exactly two Pokémon categories; Traditional Chinese (launched
2021) is not one of them, so "follow TCGplayer" yields nothing to import.
**Deferred by decision** — the region is described here so the model is complete,
but nothing is built for it. Picking it up later means sourcing the set list from
somewhere other than TCGplayer, or entering it by hand.

### Western set names are localised, because a Western set IS multilingual

Since one Western set is a single release printed in several languages, it has an
*official* name per language rather than a translation we invent: `Surging Sparks`
ships in France as `Étincelles Déferlantes`. Source is the `{{Langtable}}` on each
Bulbapedia set page, fetched once by `fetch-set-names.php` into
`data/set-names-fr.csv` and committed.

**Every set carries a French name.** Not "most" — a French storefront that names
half its catalogue in English is not a French storefront, so total coverage is the
requirement and the resolver reports any gap as a failure.

An earlier pass reported every lookup miss as "officially unchanged", which was
simply false: `Crown Zenith` is `Zénith Suprême`, `Shining Fates` is
`Destinées Radieuses`, and Bulbapedia files `Scarlet & Violet 151` under the bare
title `151`. A blank means **unknown**, never "there is no French name".

Four tiers, in order of authority, resolved offline by `resolve-set-names.php`.
Every row records which one answered:

| Tier | What it covers | Count |
|---|---|---|
| **Hand-settled** | families the two wikis split down the middle | 9 |
| **Poképédia** | the French wiki — every set is filed under its French name, and each page's Infobox states the English one (`nomen=`) and the French era (`série=`) | 104 |
| **Bulbapedia** | the `{{Langtable}}` on multi-language releases | 26 |
| **Derivation** | product never printed in French at all — McDonald's runs, trainer kits, blister exclusives | 78 |

The hand-settled tier exists for exactly one kind of problem. Bulbapedia calls five
of the nine POP sets `POP Série`, Poképédia calls the other four `POP Series`, and
taking each answer as it came printed **both spellings in the same era**. It is not
for improving on a wiki — where Poképédia names a set in English (`Gym Challenge`,
`Southern Islands`, `Neo Genesis`), that IS the French name and a prettier guess
would be wrong.

22 sets are identically named in both languages for that reason. That is coverage,
not a gap.

Bulbapedia alone left 106 sets unresolved, because it carries no Langtable on promo
runs, box products or anything recent — `Journey Together`, `Black Bolt` and
`Perfect Order` have pages with no language table at all. Poképédia closes almost
all of it: being a French wiki, the French name *is* the article title, and `nomen`
gives the join key. `Aventures Ensemble`, `Foudre Noire`, `Flamme Blanche`,
`Équilibre Parfait` and `Chaos Ascendant` all come from there.

**Poképédia writes the era into the set name** — `Épée et Bouclier Stars
Étincelantes` — because that is how the French box is branded. Our English names
deliberately drop the release code, so the French drops the era to match, except
where the English keeps it too (`EX Dragon`, `EX Rubis & Saphir`, the XY trainer
kits). Otherwise the two storefronts would disagree about what a set is called.

Derivation is rules first, curated names second, so next year's
`McDonald's Promos 2027` needs no edit:

```
McDonald's Promos 2019          -> Promos McDonald's 2019
POP Series 4                    -> Série POP 4
HGSS Trainer Kit: Gyarados & Raichu -> Kit du Dresseur HGSS : Léviator et Raichu
SWSH01: Sword & Shield Base Set -> Set de Base Épée et Bouclier
Hidden Fates: Shiny Vault       -> Destinées Occultes : Chambre Chromatique
```

Composed names resolve their own parts recursively, so an official name is reused
wherever one exists rather than re-invented — and the Pokémon in a trainer kit are
named in French, since a kit for "Gyarados" means nothing to a French buyer.

Bulbapedia lookups themselves run in three tiers of decreasing confidence:

| Tier | Strategy | Example |
|---|---|---|
| 1 | exact page titles from the group name | `Surging Sparks (TCG)` |
| 2 | era prefix stripped, unless the remainder is generic | `Scarlet & Violet 151` → `151` |
| 3 | the wiki's own search, all distinctive words required | `Expedition` → `Expedition Base Set` |

Loosening any of these produced confidently wrong names, which is worse than none:

- **Generic remainders.** Stripping the era off `SV01: Scarlet & Violet Base Set`
  leaves `Base Set`, which resolves to the 1999 WotC page — so three different
  modern sets all came back as `Set de Base`, colliding with each other and with
  the real thing.
- **Partial search credit.** Allowing one missing word let
  `SWSH: Sword & Shield Promo Cards` match the *era* page and return
  `Épée et Bouclier`.
- **Shared pages.** `Black Bolt` and `White Flare` ship together and share one
  article, so both came back as `Foudre Noire`.

The backstop is that **a French name may belong to exactly one set**. In the
Bulbapedia fetcher a collision drops the name from every claimant, because a wrong
name is worse than none. In the final resolver — where total coverage is the
requirement — a collision instead **disambiguates** by appending the era, so both
sets keep a French name and neither is ambiguous. Print-run pairs are exempt:
`Base Set` and `Base Set (Shadowless)` *should* share a name, and the qualifier is
re-attached afterwards.

### Eras are localised too

Era headings were the largest untranslated text on the French storefront, and they
are mostly the video games, so most have an official French title rather than a
translation we invent:

| | | | |
|---|---|---|---|
| Scarlet & Violet → **Écarlate et Violet** | Sword & Shield → **Épée et Bouclier** | Sun & Moon → **Soleil et Lune** | Black & White → **Noir & Blanc** |
| Diamond & Pearl → **Diamant & Perle** | Platinum → **Platine** | Mega Evolution → **Méga-Évolution** | Promos & Specials → **Promos et spéciales** |

`XY`, `EX`, `Neo`, `Gym` and `e-Card` are brand marks and are identical in both.
`Base / WotC` and `Promos & Specials` are our own coinage, so the second simply
translates; the first is a company name either way. The list lives in `ERA_FR` in
`lib/era.php`, cross-checked against the `série` field on Poképédia's set pages.

Era categories were created with `everyLang()`, so like the `Set` feature values
they needed refreshing in place rather than only at creation.

Two traps, both of which failed silently:

- **The parenthetical qualifier is not in the source.** Bulbapedia gives the name
  of the *set*, so `Base Set (Shadowless)` came back as plain `Set de Base` —
  identical to its shadowed sibling, erasing the one distinction on this site worth
  four figures. The qualifier is re-attached and translated:
  `Set de Base (Sans ombre)`, matching the chip.
- **Wiki-template fragments leak into the value.** `fr=Trésors Légendaires
  {{tt|...}}` captured as `Trésors Légendaires {{tt`, which PrestaShop then rejects
  outright with `Property Category->name is not valid` — the whole rebuild died on
  one row.

### Pokémon species names

The `Pokemon` facet lists **species** names, which is a search aid, not text
printed on a card, so it localises to the storefront language: `Charizard` in
English, `Dracaufeu` in French. Only the storefront languages are needed — there
is no reason to carry German or Spanish species names for a site that does not run
in those languages.

Source is PokéAPI (`/pokemon-species/{id}`), fetched once into
`data/pokemon-species.csv` and committed, so provisioning makes no network calls.

**Which species a card is** is derived from the same match as the title, not
guessed. The seeded catalogue took the card name's FIRST WORD, so the facet offered
`Alolan`, `Dark`, `Galarian`, `Paldean`, `Roaring`, `Tapu` and `Mr.` as though they
were Pokémon — and those stayed English on the French storefront, being words no
species table contains. Matching the longest species name inside the card name
gives `Roaring Moon → Rugit-Lune`, `Tapu Koko → Tokorico`, `Mr. Mime → M. Mime`,
and correctly yields *nothing* for Trainers and Energy.

Form prefixes are English prefixes and French suffixes, so a straight substitution
leaves a half-translated name in the wrong order. `Dark Charizard` is
`Dracaufeu Obscur`; `Galarian Zapdos V` is `Électhor de Galar V`. The list is
closed and small — Alolan, Galarian, Hisuian, Paldean, Dark, Light — and it only
fires when a species actually matched, which is what keeps `Paldean Student`
(a Trainer, not a Tauros) from becoming "Student de Paldea".
