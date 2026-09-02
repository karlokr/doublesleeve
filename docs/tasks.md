# Master task list

Everything asked for that is not yet done. This file is the record — the
session task tool is scratch space and has been wiped twice, taking the list
with it both times.

**Keep it current in the same turn as the work**, the same rule the docs follow.
Close a task by deleting its entry and, where the reasoning is worth keeping,
writing it into the doc that owns that area rather than leaving it here.

---

## Open

### 0. Mobile pass — walked end to end
Asked 2026-08-15. The goal is to use the shop on a phone and fix what is
obviously broken, without reaching for anything that turns into a rebuild.

- [x] **The black panel** — it was the FILTER drawer, not the nav. PrestaShop
      ships the facets twice and fills a different copy per width; below md the
      wide block renders empty and a Bootstrap offcanvas holds the real
      sections, parked off-screen.
- [x] **Two filter buttons** — the second belonged to that offcanvas.
- [x] **The filter menu is empty** — same cause; all 18 facets render now.
- [x] **Category cards too big** — 240x148 down to 152x84 under 576px.
- [x] Breadcrumb ran off-screen on a phone; it scrolls sideways now.
- [x] Cart line orphaned the bin onto its own row under 576px; one row again.
- [x] Walked the rest at 430px and found nothing further to fix: search results,
      product page with its copy carousel and facet selectors, cart, checkout
      step 1 and its order summary, homepage, sign-in, and the cart's picker
      modal (398px panel, 24 tiles, its action row still one line). The drawer
      was checked on checkout too, where the header is reduced — it builds and
      opens there.

**Two findings that are NOT mobile bugs**, recorded so they are not re-chased:

- **Search results have no Filters button** because PrestaShop renders no facet
  block at all on `/search` — there is nothing to open. Whether search *should*
  be faceted is a product decision, not a layout fault.
- The hero's `cc-hero__glow` measures wider than the viewport but is clipped, so
  it costs no horizontal scroll. `document.scrollWidth` equals the viewport on
  every page walked.

### 0a. One badge for grader and grade — mostly done
Asked 2026-08-15. Done for everything the SERVER renders (product tiles, cart
lines) and for the tiles the printing expansion builds client-side. **Still to
check:** the product page's own badge line, which is built from the selected
combination — it shows the ungraded chips correctly, but a graded SKU has to be
selected through the facet control to confirm the fold applies there too.
Original ask: Condition and grading are two chips today; on a graded card
they are one fact and should read as one badge, in the form the hobby actually
uses — `PSA 10`, `BGS 9.5`. Grade words keep their accepted short forms so the
badge stays short (`Gem Mint` → `GEM MT`). Everywhere a badge appears: product
tiles, product page, cart lines, the copy pickers.

### 0b. Stock a TAG card, and write down the grader policy — done
Two TAG slabs seeded (Umbreon VMAX TAG 10, Paldean Fates Mew ex TAG 9.5); ACE
and SGC retired from the vocabulary by
`src/ops/migrations/retire-ungraded-graders.php`; policy written into
`pokemon-catalog.md`. Original ask: Seed one or two TAG-graded cards. Document that the shop
carries **PSA, BGS, CGC and TAG only** — no ACE, no others. Those four are the
only slab frames that exist, which is the constraint the policy follows from.
This closes the old "SGC and ACE frames" task below by deciding not to carry
them.

### 0c. Match Collectr's set naming — English done, Japanese open
Asked 2026-08-15. Collectr is the reference a collector already has open in
another tab, so a set named differently here is a set they cannot find.

**English is done.** Their catalogue is keyed on the same TCGplayer group ids
ours is, so the join is exact — no name matching. All 175 of their English sets
exist in our database; 163 names already agreed and 10 were renamed by
`src/ops/catalog/align-collectr.php`, which is idempotent and re-runnable as their
list moves. Their list is checked in at `src/ops/data/collectr-sets-en.tsv`.

**Two are held back deliberately.** Collectr calls group 604 "Base Set
(Unlimited)" and 1663 "Base Set (1st Edition & Shadowless)" — better names than
ours, and exactly the disambiguation we hand-built the Unlimited printing rename
for. We cannot take them yet because the shadowed/shadowless split is derived
from the category NAME: shadowless if the name ends "(Shadowless)", shadowed if
a sibling exists called "<name> (Shadowless)". Both Collectr names satisfy
neither rule, so the Not Shadowless chip and the print-run facet would go quiet
with nothing on screen to say so.

  **To close it:** key that detection on the group id, the way
  `src/ops/lib/printing.php` already keys the printing renames, then delete the
  `HOLD` list in `align-collectr.php`. Check the other name readers at the same
  time — `audits/audit-parallel-sets.php`, `audits/audit-printings.php`,
  `media/seed-nav-images.php`, `catalog/fetch-set-names.php`.

**Japanese is not started, and does not join the same way.** Collectr numbers
Japanese sets with its own ids (`1000147` for Shiny Treasure ex) rather than
TCGplayer's (`23601`), so there is no id to join on and the match has to be made
on names — which are close but not equal (`Pokemon Card 151` vs `Pokemon 151`,
`TAG TEAM GX: Tag All Stars` vs `Tag Team GX All Stars`). Several of their
Japanese names are not single names at all but parenthesised alternatives, e.g.
`(Towering Perfection,Perfect Skyscraper)`. 201 sets, needing a name-matching
pass with human review of the ambiguous ones.

**Their images were fetched and then deleted at the user's request** — set
artwork keeps coming from pokemontcg.io and the Bulbagarden Archives.

### 1. Make the shop deployable — see docs/deployment.md
Asked 2026-08-15. Plan written. Five pieces, in the order that makes each next
one cheaper.

**a. Put the shop in its own image — DONE.** `devops/image/Dockerfile` is
`FROM prestashop/prestashop:9.1.4-8.3` plus `src/modules/` and `src/ops/`;
`make image` tags it from the git sha. `devops/prod/compose.yml` runs that image
and mounts **only** the state paths — `img/`, `var/`, `upload/`, `download/`,
`app/config` — never `/var/www/html` itself, which would hide the baked code.
Built and verified: the modules, `/provisioning` and the php ini are all inside.

  Still to do before it deploys for real: push to GHCR from CI, and simplify
  `install-theme-module.sh`, which only copies files because the module used to
  live outside the image. In production it should register hooks and nothing
  else.

**b. Put the images on a diet, before engineering any backup for them.**
3.6 GB, of which 703 MB is originals kept at source resolution (largest 4.7 MB)
and 2.3 GB is PrestaShop generating **twelve** sizes per image, several of them
near-duplicates the theme never asks for. Nothing is webp. Cap originals at
intake, prune `image_type`, enable webp — a few hundred MB is the realistic
target. There is no sense building transport for 3.6 GB that ought to be 400.

**c. Back up what is left.** `make backup` is database-only, so the imagery
exists in exactly one place on one machine.

**d. A migration ledger — DONE.** `src/ops/deploy/migrate.php` keeps a
`cc_migration` table and applies what is missing in filename order. It records a
checksum too, and **stops** if an already-applied migration has changed on disk:
that means production and the repository disagree about what the database is,
which is a human's decision, not a deploy's. `--baseline` records without
running, for a database that already has them; `--dry` lists. This database is
baselined at 6.

  New migrations should be date-prefixed (`2026-08-15-name.php`) so filename
  order is total. The six that predate the runner are unprefixed and baselined.

**e. CI and releases — DONE.** `.github/workflows/ci.yml` runs on every PR:
PHP, JS and shell syntax, both compose files parse, and the image builds
(without pushing). `.github/workflows/release.yml` runs when a PR merges into
`production`: it works out the next semver from a `major`/`minor` label on the
PR (patch by default), builds and pushes to GHCR tagged with the version, the
sha and `latest`, then creates the git tag and GitHub release.

  Needs from you: create the `production` branch, and confirm GHCR package
  permissions the first time it pushes.

**f. One ordered `make bootstrap`, and a second environment.** Every target
needed to build a shop from nothing exists, but the order is tribal knowledge
and order-sensitive — `setup.php` recreates taxonomy the align scripts delete,
which has resurrected retired data twice. The sequence is written down at the
end of `docs/deployment.md`; encode it, then use it to stand up somewhere to
rehearse migrations that is not production.

### 2. Re-anchor graded prices via the price-sync graded pass
Graded combinations are still priced off the ungraded anchor. Run or extend the
graded pass in `src/ops/pricing/price-sync.php` so PSA/BGS/CGC tiers take their own
PriceCharting columns.

On PriceCharting card pages the `td` ids `used_price` / `new_price` /
`manual_only_price` actually mean **Ungraded / Grade 8 / PSA 10** — the names
lie.

### 3. Fill in Rarity for the four Japanese singles missing it
Four Japanese single products carry no `Rarity` feature. Rarity is still recorded
and still drives the badge for every card; only the rarity row above the facet
selectors is hidden for Japanese cards.

### 4. Finish seeding Japanese stock
Japanese singles and graded now use the serialised copy system, but their stock
seeding is incomplete. Bring `cc_card_copy` and `stock_available` into step so
`COUNT(available copies) == quantity` holds for every Japanese SKU, then re-run
the `copies-init` invariant check.

### 5. Widen the sealed `Set` vocabulary, with a supplemental flag
All 62 sealed products currently map to a real expansion, so nothing is broken —
but the model forces a `Set` that some sealed stock will not have: era promos,
multi-set bundles, accessories, mystery boxes.

TCGplayer never leaves the group empty. It widens the group vocabulary to admit
promo, era and standalone buckets, and flags the non-expansion ones with
`isSupplemental` so they can be included or excluded. Do the same: allow
non-expansion values in the `Set` feature plus a supplemental marker that keeps
them out of the singles' set list.

Decided with the user 2026-08-15. See `information-architecture.md`,
"Sealed carries its facts as features, not variants".

### 6. Fix the sealed feature gaps
Found while adding `Card Language`; none of them block anything today.

- `Sealed Product Type` is set on **54 of 62** — the eight Japanese items
  (products 387–394, seeded by `src/ops/inventory/seed-japanese.php`) never get one,
  so they are missing from that facet.
- `Seal Status` is `Factory Sealed` on all 62. A constant carries no information
  and it still occupies a filter rail in `src/ops/setup/facets.php`.
- `Release Year` is set on **0 of 62** and also has a rail, which is therefore
  always empty.
