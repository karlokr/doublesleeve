# DoubleSleeve

Pokémon TCG store. PrestaShop 9.1.4 on Docker, provisioned for singles, sealed
product and graded slabs.

## Repository layout

```
src/                  what ships INTO the image
  modules/            the four PrestaShop modules: theme, copies, search, i18n
  ops/                the operator's scripts, by what they act on
                      catalog/ inventory/ media/ pricing/ migrations/
                      audits/ setup/ installers/ lib/ assets/ data/
devops/               how it is built and run
  image/              Dockerfile, php config, the cron sidecar
  dev/                local compose - bind-mounts src/ so edits are live
  prod/               production compose - runs the tagged image, mounts no code
docs/                 how the shop is meant to work, and why
Makefile              every operation, one target each
```

Nothing under `src/ops/` refers to a host path — every script addresses
`/provisioning/...`, the path it is mounted or copied to. That is why the layout
above it can move without touching a single script, and it is worth preserving.

## Quick start

```bash
make up && make provision
```

First run pulls ~3 GB of images and installs PrestaShop; budget 5–10 minutes.
`make provision` is idempotent — re-run it any time.

| Service     | URL                                       |
|-------------|-------------------------------------------|
| Storefront  | http://localhost:9080/                    |
| Back office | http://localhost:9080/admin-cryptocards/  |
| phpMyAdmin  | http://localhost:9081/                    |
| Mailpit     | http://localhost:9025/                    |
| Meilisearch | http://localhost:9700/                    |

Admin credentials are `ADMIN_MAIL` / `ADMIN_PASSWORD` in `.env` (generated with a
random password on first `make up`, git-ignored).

Ports are 9080/9081/9025 rather than the usual 8080/8081/8025 because those were
already taken on this machine — including an unrelated `prestashop` container on
8081. Change them in `.env` if you free them up.

## Make targets

```
make up            Start the stack
make provision     Apply shop config + catalog model + theme (idempotent)
make seed          Seed ~320 real products with images
make search-index  Rebuild the Meilisearch index
make purge-demo    Delete PrestaShop's demo catalogue (run once, before real stock)
make down          Stop (data survives in volumes)
make logs          Tail PrestaShop logs
make shell         Shell into the PrestaShop container
make dbshell       MySQL shell
make backup        Dump the database to backups/
make image         Build the deployable image
make release       Cut a release: build, push to GHCR, tag, publish
make reset         DESTROY all data and reinstall from scratch
```

## Releasing and deploying

There is no CI. Releases are cut from a developer machine, which changes nothing
about the artifact: still a semantic version, still three image tags, still a
real GitHub release with the deploy and rollback commands in it.

```bash
make release-dry          # say what would happen, do none of it
make release              # patch:  v1.2.3 -> v1.2.4
make release BUMP=minor   # v1.2.3 -> v1.3.0
make release BUMP=major   # v1.2.3 -> v2.0.0
```

Every check runs before the build, because a half-published release is worse
than none: a dirty tree, a HEAD that does not match `origin`, a tag that already
exists. The git tag is created only after the image is pushed, so a release can
never name an image that does not exist.

Production is a Docker Swarm stack. Deploying a version is changing the tag and
redeploying, and rolling back is the same command with the previous one:

```bash
APP_IMAGE_TAG=v1.2.4 docker stack deploy -c devops/prod/stack.yml doublesleeve
```

Nothing runs afterwards. The container applies pending migrations itself on
start, and Swarm replaces tasks one at a time and rolls back if the new one does
not come up healthy. See [docs/deployment.md](docs/deployment.md), particularly
"The upgrade contract" - a rollback is safe because migrations are forward-only
and backward-compatible, not because of anything the deploy does.

## What `make provision` sets up

- **Shop identity** — name, and stock rules suited to singles: overselling denied,
  quantities shown, low-stock warning at 3.
- **Languages** — English (`en-US`) and Canadian French (`fr-CA`). Every UI string
  the modules inject ships in both.
- **Currencies** — CAD (default) and USD, rates maintained from the Bank of Canada.
- **Attributes** (variant-generating, in selector order) — Card Language, Printing,
  Grading, Condition. They describe the physical copy you ship; the Condition axis
  carries both the raw trade grades (NM–DMG) and the slab tier labels
  (`10 Gem Mint`, `10 Pristine`, `10 Black Label`, `9.5 Gem Mint`).
- **Features** (filterable metadata) — Region, Set, Rarity, the Pokémon facet
  (all 1,025 species), Card Number, Stage, Regulation Mark, Format Legality,
  Print Run, Release Year, Artist, and the sealed facts (Seal Status, Pack Count).
- **Categories** — `Pokémon → Singles → Western / Japanese → era → set`, with
  Sealed organised by product type and Graded serving as the entry point to the
  grading filter. See [docs/architecture.md](docs/architecture.md) for why region
  is a tree level under Singles and a facet everywhere else.
- **Faceted search templates** — per section, Region and Card Language at the top
  of the rail.
- **Storefront** — menu rebuilt from the real categories with region tabs and
  stock-aware pruning, live homepage stats, set directory page.

See [docs/architecture.md](docs/architecture.md) for what this platform is and how
the pieces fit, [docs/information-architecture.md](docs/information-architecture.md)
for the tree's shape, [docs/operations-pipeline.md](docs/operations-pipeline.md)
for the price engine, serialised inventory and intake pipeline, and
[docs/image-pipeline.md](docs/image-pipeline.md) for how every image on the
storefront is generated — cutouts, slab frames, card backs and set logos.

[docs/deployment.md](docs/deployment.md) covers how this is deployed and what a
fresh environment does and does not get.

[docs/tasks.md](docs/tasks.md) is the master task list: everything asked for that
is not yet done, kept current in the same turn as the work.

## Look and feel

The storefront runs a custom design system — neutral chrome over dark surfaces —
the card art is the colour — delivered by the `cryptocards_theme` module rather than a fork of
Hummingbird, so the theme keeps taking upstream updates.

```bash
make provision     # installs/updates the theme module
```

It restyles the header and mega menu, homepage hero and category tiles, product
cards (with condition and finish chips derived from each card's default
combination), the filter rail, product page, set directory, footer and the instant
search dropdown.

## Seed data

```bash
make seed          # ~320 real products with images, then reindex
```

Real inventory, not lorem ipsum:

- **Singles** from [pokemontcg.io](https://pokemontcg.io/) — real names, sets,
  rarities, artists, artwork and TCGplayer market prices, across 12 sets from Base
  Set to Prismatic Evolutions.
- **Sealed** from [tcgcsv.com](https://tcgcsv.com/), a free daily mirror of
  TCGplayer's own catalogue — real ETBs, booster boxes, bundles and tins with real
  product photography, rather than invented products.
- **Set logos** for all 174 set categories, composited onto the theme's surface
  colour so wide transparent PNGs don't render with white bars.

Singles get one combination per condition stocked, priced off the NM market price
by the standard ladder (NM 100%, LP 85%, MP 70%, HP 55%, DMG 40%) — the pricing
model a real shop runs on. Chase cards get quantity 1–2; bulk gets 3–18.

**On imagery:** card and product images are hotlinked-then-cached from those
catalogues for development. A production shop should photograph its own stock —
it is both the copyright-safe path and what buyers expect for graded and
high-value singles.

## Adding stock

The seeders above are **dev fixtures**. Real stock enters one card at a time —
the platform's unit of work is one copy of one card, and adding it touches
nothing else:

```bash
make add-card GROUP=2545 NUMBER=SWSH075            # one Special Delivery Charizard, NM
make add-card GROUP=23601 NUMBER=347/190 QTY=2 CONDITION="Lightly Played"
```

Region, set placement, derived titles in both languages, features, the
corner-cut scan and the price-engine wiring all follow from the TCGplayer group
+ collector number match. If the listing already exists, the copy lands on the
right SKU instead of minting a duplicate. This CLI is the same operation the
photo-match admin flow (the copies module) will drive with a camera.

## Alignment with TCGplayer

The catalogue mirrors TCGplayer's model — `category → group → product → SKU`, where
a SKU is `product × Printing × Condition × Language`.

```bash
make align         # printing/rarity/card-type vocabularies, from their extendedData
make sets-align    # set taxonomy from their 217 groups
make sku-rebuild   # combinations as real SKUs, Printing price-bearing
```

| Layer | Status |
|---|---|
| Sets | ✅ their 217 groups, exact names (`SV03: Obsidian Flames`), `groupId` stored as the join key |
| Printing | ✅ their 7 values, and **price-bearing** — 1,011 SKUs across 264 cards, 140 with 2+ printings |
| Rarity | ✅ their 15-value vocabulary, read per product from their `extendedData` |
| Card number, Card Type, Stage | ✅ from their `extendedData` |
| Condition | ✅ their exact five, verified against TCGplayer's published conditioning standards |
| Language | ⚠️ ours is a superset guess — TCGCSV exposes no SKU data, so this needs the TCGplayer API to confirm |
| Card stats (HP, attacks, weakness…) | ⬜ deliberately not modelled |
| Catalogue size | ⬜ we list stock, they list a marketplace — see below |

**Stock is per SKU, and the storefront tells the truth about it.** Only
printing/condition pairs that exist *and* have stock are selectable —
`PS_DISP_UNAVAILABLE_ATTR` ships as `1`, which lets a buyer pick a combination that
was never in the catalogue, so provisioning forces it to `0`. The product page shows
two numbers: how many of the exact SKU selected, and the total across every variant
of that card. Both update live as the selectors change.

**Two deliberate deviations, both load-bearing:**

1. **Eras are inserted above sets, per region.** TCGplayer's organisation is
   genuinely flat — 217 Western groups and 454 Japanese ones, no series field. A
   flat list is not navigable, so sets are filed under the era/block collectors
   actually shop by (`src/ops/lib/era.php`, `src/ops/lib/era-jp.php` — the Japanese block
   list is its own, ADV/PCG/LEGEND are not Western eras renamed), beneath a print
   region level (`src/ops/lib/region.php`).
2. **Set artwork comes from pokemontcg.io.** TCGplayer publishes no set logos.
   114 of the 217 groups get artwork; the rest render clean without it.

**Catalogue size is not an alignment gap.** TCGplayer is a marketplace listing every
card that exists; this is a store that lists what it physically owns. Their *taxonomy*
is the thing worth matching — so that every card you do stock lands in the right set,
with the right printing and the right number — and it is matched for all 217 groups.
Seeding their whole catalogue would create tens of thousands of products you have no
stock for. Real inventory should arrive through the intake pipeline
([docs/operations-pipeline.md §3](docs/operations-pipeline.md)), which creates the
product when a card is physically scanned in.

We also keep filters TCGplayer doesn't expose — Pokémon name, Pokédex №, Regulation
Mark, Format Legality — because they're among the most useful facets for competitive
buyers. Additions, not disagreements.

## Serialised inventory

Every physical single is its own record, so the shop can say *which* card you are
buying rather than just how many it has.

```bash
make copies-init   # one card_copy per unit in stock (singles, graded, sealed)
```

**7,914 copies.** Everything sellable is serialised: raw singles, graded slabs
(as copies of the combination they live on) and sealed product. Sealed was
originally excluded on the grounds that one factory-sealed box is interchangeable
with another — true of the cards inside, false of the box, whose condition,
dents, shrink-wrap and print run all vary. A buyer paying box prices wants to see
the one being shipped.

Each copy carries a short **serial** (`BW7S98CY`, Crockford-style alphabet with no
I/L/O/U so a scuffed label can't be misread), ready to print as a QR on the sleeve.
`COUNT(available copies) == stock quantity` is enforced and re-verified on every run.

### Which photo a shopper sees

Decided **per SKU**, not per product. The stock photo always owns the gallery —
on the product page and in the listing tile — until the shopper picks a serial:

| Situation | Shown |
|---|---|
| Any count, some photographed | Stock photo + **"choose your exact card"** picker |
| 1 available, no photo yet | Stock photo + serial, labelled as a reference scan |
| 2+ available, none photographed | Stock photo, and that photography is pending |
| All copies `stock_only` | Stock photo, and that these are sold by condition |

The stock photo is the best image on the page — for a graded copy it is the
composited slab showing the holder and its label — so a listing never quietly
downgrades itself to a snapshot nobody asked for. A one-of-one goes through the
picker like anything else: you see the photograph when you choose to.

Serialising is not photographing. A $0.30 common with eighteen interchangeable
copies is flagged `stock_only` and says so, rather than promising a photo that is
never coming.

**Choosing is always optional.** Take no action and the oldest available copy ships
(FIFO, which is also correct stock rotation). Nobody is forced through a gallery to
buy a common.

### The chosen card follows the buyer through checkout

Picking a copy is not decoration — the specific physical card is reserved and
tracked to the order.

```
add to cart          → that serial goes status=reserved, bound to the cart, 30-min hold
no copy chosen       → oldest available copy reserved (FIFO)
quantity up          → reserves another, FIFO
quantity down        → releases the AUTO-PICKED copy first; an explicitly chosen
                       card is released last
line removed         → releases everything that line held
order validated      → status=sold, stamped with the order id
cart abandoned       → released after 30 min by a job running every 5 minutes
```

The invariant across all of it: **`available + reserved == stock quantity`**, checked
on every release run. PrestaShop only decrements stock at order validation, so a
reserved copy is still catalogue-available — it is simply spoken for.

**Concurrency is handled by the UPDATE itself**, not by a read-then-write:

```sql
UPDATE cc_card_copy SET status='reserved', id_cart=?
 WHERE id_copy=? AND status='available'   -- 0 rows affected = someone beat you
```

Verified: two carts demanding the same serial → one wins, the other falls through
to a different copy. Never double-booked.

```bash
make copies-release   # release expired holds + verify the invariant
```

Copy photos come from the intake pipeline
([docs/operations-pipeline.md §3](docs/operations-pipeline.md)); today every copy is
`photo_state = pending`, so the storefront correctly falls back to stock imagery.

## Price engine

Repricing from live market data, running every 12h. **It is in dry-run and will not
change a price until you tell it to.**

```bash
make price-setup    # once: tables + source map
make price-sync     # propose only, journals every decision
make price-report   # read what it would have done
make price-apply    # actually reprice (still fully gated)
```

**How it decides.** TCGplayer is the *anchor*, not one vote among several — it is
the market you sell into. TCGCSV and pokemontcg.io both mirror TCGplayer, so they
count once, not twice. Cardmarket (EU) is corroboration: it moves confidence, never
the price. Everything is normalised to CAD using the Bank of Canada rate.

The first dry run is why it works this way — TCGplayer and Cardmarket disagreed by
**55% on average**, because they are different economies. Averaging them would have
priced the shop off a market it doesn't sell in.

**Guard rails**, all journaled: ±15% max move per cycle (clamped, not rejected),
a hard price floor and optional per-product cost basis, >5× swings rejected as
mismatched products, a per-product freeze flag, and percentage guards disabled below
$2 so a 1¢ common going to 21¢ isn't treated as a 2000% event.

Nothing is applied on low confidence — it goes to the review queue instead. Read
`cc_price_journal` for the full audit trail; every run is revertible from it.

Promotion path: run `make price-sync` for a couple of weeks, read the reports, then
add `--apply` to the cron lines in [provisioning/crontab](provisioning/crontab).

**Scheduling** is a `cron` sidecar container built from the same PrestaShop image
(the base image ships no cron daemon), sharing the same volume so jobs run the exact
code the site serves. Cron times use `TZ` from `.env` — it defaults to UTC, so set it
to your shop's timezone or "00:15" means 00:15 UTC.

The full design, including the parts not yet built, is in
[docs/operations-pipeline.md](docs/operations-pipeline.md).

## Search

Meilisearch backs the storefront search box via the `cryptocards_search` module in
[modules](modules). PrestaShop's native search is a MySQL
LIKE query — no typo tolerance and no synonyms — which fails the two most common
card-shop queries: misspellings and trade slang.

```bash
make search-index      # rebuild the index after catalogue changes
```

Verified working: `charzard` → Charizard, `obsidan flames` → Obsidian Flames,
`obf` → Obsidian Flames, `リザードン` → Charizard.

Queries are proxied through the shop's own front controller, so the Meilisearch
master key stays server-side and never reaches the browser. If the search container
is down the box falls back to a normal form submit rather than erroring.

Synonyms for `alt art`, `etb`, `psa10`, `nm`/`lp`/`mp` and similar are configured
but resolve against **product** fields, so they return nothing until real inventory
exists. That is expected, not a fault.

The set list lives in [provisioning/data/pokemon-sets.csv](provisioning/data/pokemon-sets.csv),
generated from the [Pokémon TCG API](https://pokemontcg.io/). Add a row and re-run
`make provision` to create new set categories as expansions release.

The reasoning behind the catalog model — and how to actually list a card — is in
[docs/pokemon-catalog.md](docs/pokemon-catalog.md).

## Gotchas that will bite you

- **Hard-refresh after any asset change.** PrestaShop bundles module CSS/JS into
  `themes/hummingbird/assets/cache/`. The install scripts clear both that and
  `var/cache`, but a browser holding the old HTML will keep loading the old bundle
  — use `Ctrl+Shift+R`. Several "the change didn't apply" moments were only this.
- **The theme has its own search autocomplete.** Hummingbird binds an AJAX
  dropdown to the same input, so before it was suppressed the search box opened
  *two* competing panels. `cryptocards_search` now removes the `js-search-widget`
  hook class and hides `.ps-searchbar__dropdown`.
- **Don't style `.form-control` globally.** An early rule gave every input the
  search box's pill shape and 2.6rem icon padding, which deformed every quantity
  stepper in the shop. Search styling is scoped to `.ps-searchbar__input`.
- **The header needs an explicit high z-index.** Product cards and category panels
  create their own stacking contexts, so at equal z-index the mega menu and search
  dropdown render *underneath* page content.

## Upstream bugs worked around

Two are handled automatically; both are PrestaShop-side, not configuration
mistakes, and both will bite you if you edit the compose file.

1. **`PS_FOLDER_ADMIN` breaks the installer.** The entrypoint renames the admin
   folder *before* running the installer, but `Install.php:1204` hardcodes
   `$adminFolder = 'admin-dev'` when installing bundle assets — so any custom
   value aborts the install at `finalize()`. The stack therefore installs with
   `admin-dev` and `make provision` renames it to `PS_FOLDER_ADMIN` afterwards.
   The rename also clears `var/cache`, because the compiled Symfony container
   stores absolute admin paths and every back office page 500s otherwise.

2. **`PS_HANDLE_DYNAMIC_DOMAIN=1` causes an infinite redirect.** It installs
   `docker_updt_ps_domains.php` as the Apache `DirectoryIndex`, and that script
   unconditionally ends in `Tools::redirect('index.php')` — which with friendly
   URLs resolves back to `/`. It is pinned to `0`; `PS_DOMAIN` is fixed anyway.

## Notes

- **No `en-CA` storefront language.** PrestaShop publishes no en-CA translation
  pack (only `en-US`, `en-GB`, `fr-CA`). See the rationale in
  [docs/pokemon-catalog.md](docs/pokemon-catalog.md#languages-and-currencies) —
  short version: currency and tax, not language, are what differ between Canadian
  and US buyers.
- **Mail is captured, never sent.** PHP's `sendmail_path` points at Mailpit, so no
  dev order confirmation can reach a real customer. Read them at
  http://localhost:9025/.
- **The logo is a CSS wordmark, not an image.** The theme hides PrestaShop's
  placeholder logo and renders "DoubleSleeve" as foil-gradient text. Drop a real
  logo in *Design → Theme & Logo* and remove the `.header-bottom__logo a::after`
  rule in `theme.css` when you have proper branding.
