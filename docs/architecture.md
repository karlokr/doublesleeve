# Architecture

## What this actually is

DoubleSleeve is a **selling platform for one collector's physical cards**. The
target workflow, end to end:

1. Photograph a card you hold.
2. **Match** it to its catalogue identity (set + collector number).
3. Everything else derives from the match: the title in every storefront
   language, the set/era/region placement, the features, the stock scan, the SKU.
4. The **price maintains itself** — a 12-hourly engine keeps every listing at
   market, journaling every decision.

Everything in this repository is either that workflow, or scaffolding that
builds the catalogue the workflow matches against. Judge any change against the
platform's unit of work: **one copy of one card**.

## The one rule that falls out of that

> Adding a card must touch that card and nothing else.

This rule was violated for most of the repo's history: stock entered through
batch seeders, and placing products onto their sets was a side effect of the
catalogue importer — so adding one card re-homed 277 unrelated products to do
it. The batch shape was right for bootstrapping a shop from nothing and wrong
for operating one.

The split now is:

| concern | unit of work | where |
|---|---|---|
| Catalogue (sets, eras, regions) | a region's whole taxonomy | `ops/catalog/` — cheap, product-free |
| Stock | one copy of one card | `ops/inventory/add-card.php`, and eventually the copies module's photo-match admin |
| Price | one SKU, every 12 h | `ops/pricing/price-sync.php` via cron |
| Bulk repair | everything, on purpose, explicitly | `make catalog-repair` (`--rehome`) |

The seeders (`ops/inventory/seed-*.php`) remain as **fixtures**: they bootstrap
a believable dev shop. They are not the ingestion path.

## The catalogue model

The catalogue answers "what card is this?"; stock answers "which copies do I
hold?". They are different tables because they are different facts.

- **Identity**: `cc_card_identity` (+`_lang`) — collector number, set category,
  card language, per-language card name. Titles are **derived** from identity
  (`composeCardTitle`), never authored.
- **Tree**: `Pokémon → Singles → <Region> → <Era> → <Set>`. Region is a tree
  level under Singles because the era lists genuinely differ per region (Japan
  has ADV/PCG/LEGEND, never had WotC); it is a *facet* on Sealed/Graded because
  their child lists (product types, graders) are region-invariant. See
  `ops/lib/region.php`.
- **SKU axes** (attribute groups, in selector order): Card Language → Printing →
  Grading → Condition. Copy-state facts live on combinations; card-level facts
  (Rarity, Set, Region, Pokémon…) are features.
- **Grading is a copy state**, not a product split — see
  `docs/pokemon-catalog.md`. The (Grading, Condition-tier) pair identifies a
  slab market; only combinations that physically exist are created.
- **Serialisation**: `modules/cryptocards_copies` gives every physical single
  its own row, code, photos and reservation lifecycle. This is the platform's
  core module; the photo-match admin flow lands here, and it should call the
  same operation `add-card.php` performs from the CLI.

## The price engine

`ops/pricing/price-sync.php`, cron at 00:15/12:15, **journal-only until
promoted** (add `--apply` in `ops/crontab` once the journal has earned trust).

- **Raw stock**: TCGplayer market is the anchor (the market this shop sells
  into); pokemontcg.io mirrors it (same family, one vote) and brings Cardmarket
  (EUR) as corroboration that moves confidence, never the price. FX is Bank of
  Canada, twice daily, cached.
- **Graded stock**: slabs have no TCGplayer row; their market is auctions.
  Three families (`ops/lib/graded-quotes.php`): PriceCharting's per-tier
  estimate, PriceCharting's per-tier sold-comp tables, and 130point's eBay sold
  search — recency-weighted (21-day half-life; slab markets step fast), blended
  by weighted median, same guard rails, journaled per combination. Slab totals
  are re-anchored when sources are down so a raw base move never silently
  reprices a slab.
- Every decision — applied, proposed, clamped, floored, held, review — lands in
  `cc_price_journal` with its sources. `make price-report` reads it.

## Repository layout

```
modules/        The application: PrestaShop modules (theme, search, copies, i18n)
ops/            Operational toolkit, mounted at /provisioning in the containers
  setup/        One-time shop provisioning + schemas (idempotent, `make provision`)
  catalog/      Taxonomy + vocabulary sync against TCGplayer/pokemontcg.io/Poképédia
  inventory/    Stock: add-card.php (the real path), seed-* (dev fixtures), copies lifecycle
  pricing/      The price engine, reports, FX
  media/        Image pipeline: background cutouts, nav/tile artwork, slab frames + photos
  migrations/   One-time model migrations, kept for provenance and fresh installs
  audits/       Read-only checks against TCGplayer's catalogue
  installers/   Module copy+install scripts (modules are mounted at /modules)
  lib/          Shared vocabulary: names, eras, regions, cutouts, graded quotes
  data/         Fetched catalogue data (CSV/JSON) — regenerate via ops/catalog/fetch-*
docker/         Container build context (php config, cron sidecar)
docs/           This file, plus the catalogue, operations and image-pipeline references
```

Container paths are stable on purpose: `./ops` mounts at `/provisioning`,
`./modules` at `/modules`. Scripts reference `/provisioning/...` absolutely and
survive host-side reorganisation.

## Known gaps (honest list)

- **The photo-match admin flow does not exist yet.** `add-card.php` is its
  engine; the copies module has serialisation and reservations but no camera- 
  and-match UI. This is the next substantial build.
- Card Language on a Western listing models EN/FR/DE/IT/ES variants, but only
  EN/JP stock has ever been seeded; non-English Western SKUs are untested.
- `pokemontcg_card_id` is only mapped for fixture stock; cards added via
  `add-card.php` run on the TCGplayer anchor alone (medium confidence) until
  `ops/catalog/align-tcgplayer.php` maps them.
- Chinese region: modelled in vocabulary (`ops/lib/region.php`), no source, no
  categories, no stock.
- Graded tiers with no structured price source (BGS 10 Pristine/Black Label,
  CGC 10 Pristine) exist in the vocabulary but are never auto-priced; they need
  per-slab pricing at listing time.
- **SGC and ACE have no slab frames.** `ops/media/make-slab-frames.php` generates
  a frame per grader per grade from a template photograph of that company's
  holder, and no template was ever supplied for these two. `slabFramePath()`
  returns null for them and the listing falls back to a plain scan, deliberately:
  dressing a card in a competitor's holder misrepresents the item.
- A graded combination stores its price as an **impact** on the product price, so
  a raw re-price drags every slab on that card with it until the next
  `price-sync` re-anchors them from absolute graded totals. Between runs the
  displayed graded price can disagree with what it was listed at.
- 4 of the 42 Japanese singles carry no Rarity feature value, so their badge line
  is missing that chip.
