# Deployment

## The short answer

**No — a fresh deploy of this repository does not give you the shop you have.**
It gives you the code that can build one. The catalogue and every image live in
Docker volumes that git has never seen.

| | Where it lives | In git? |
|---|---|---|
| Modules, theme, ops scripts | the repo | yes |
| Source assets — slab frames, fonts, card backs, seed JSON, set CSVs | the repo | yes |
| Categories, products, attributes, features, prices, orders | `db_data` volume | **no** |
| 18,839 generated images, 3.6 GB — scans, cutouts, slab composites, nav art, set logos | `ps_html` volume | **no** |
| Search index | `meili_data` volume | no (rebuildable) |

`make backup` dumps the database. **It does not touch the 3.6 GB of images**,
which exist in exactly one place on one machine.

## The decision that shapes everything else

There are two ways to deploy a shop, and only one of them survives contact with
a real customer.

**Rebuild from scripts.** Every deploy re-runs the pipeline and regenerates the
catalogue. Attractive because the repo is then the whole truth. It does not
work here, for four reasons:

- It is **not hermetic.** The pipeline fetches from tcgcsv, pokemontcg.io, the
  Bulbagarden Archives, PriceCharting and the Bank of Canada. A deploy that
  needs five third parties to be up is a deploy that fails on their schedule.
- It is **not deterministic.** Prices move, sets get added. Two deploys of the
  same commit produce different shops.
- It is **slow.** 3.6 GB of imagery is fetched, cut out and composited.
- It **cannot include orders.** The moment a customer buys something, the
  database holds facts no script can regenerate.

**Migrate forward.** The database is a living thing that moves from state to
state; deploys carry it forward, never rebuild it. This is the model to adopt,
and most of the machinery already exists — every script under `ops/migrations/`
is written to be idempotent and to refuse to damage stock it did not expect.

The consequence worth being explicit about: **once the shop takes its first
order, production's database is the source of truth**, and the repo's job is to
change it safely rather than to reproduce it.

## What deploys, and how

Four kinds of change, three different mechanisms:

**Code** — modules, theme, ops scripts. Ships from git. `modules/` is a
read-only bind mount and the shop runs a *copy*, so a deploy is
`git pull` plus the installer for whatever changed:

```bash
docker exec cryptocards-shop bash /provisioning/installers/install-theme-module.sh
docker exec -u www-data cryptocards-shop rm -rf /var/www/html/var/cache/prod
```

**Schema and configuration** — attribute groups, features, facet templates,
vocabulary. Ships as ordered idempotent scripts under `ops/setup/` and
`ops/migrations/`. This is the part that needs a ledger (below).

**Catalogue data** — sets, products, prices. Does *not* ship. It is changed in
place by running an ops script against the live database, exactly as it is
locally: `make add-card`, `make price-sync`, `make sets-align`.

**Images** — generated once, then carried. The generators are already
self-healing (`slab-photos.php` re-shoots when a frame is newer than the photo),
so they run when their source changes, not on every deploy.

## The four gaps, worst first

### 1. The images are unbacked-up single-copy state

3.6 GB in one Docker volume, on one machine, in no backup. Losing that volume
means re-fetching thousands of scans from third parties and recompositing every
slab — days of work dependent on those sources still serving the same files.

This is a bigger risk than anything about the deploy itself, and it is the
cheapest to fix: extend `make backup` to tar `img/` alongside the SQL dump, and
put both somewhere off the machine.

### 2. Nothing records which migrations have run

There is no ledger table. Knowing what to run on a deploy is currently a person
remembering. Every migration is idempotent, so the fallback is "run them all",
which works and gets slower and more frightening as the list grows.

A `cc_migration` table recording filename and applied-at, plus a runner that
applies what is missing in filename order, turns deployment into one command.

### 3. There is no single ordered bootstrap

The targets to build a shop from nothing all exist, but the *order* is tribal
knowledge — and it is known to be order-sensitive. `setup.php` recreates
taxonomy that the align scripts deliberately delete, so running it after them
resurrects retired data. That has happened twice.

One `make bootstrap` that runs the full sequence in the correct order, with the
hazard encoded rather than remembered, is what makes a second environment
possible at all.

### 4. There is only one environment

One `.env`, one compose file, one machine. There is nowhere to rehearse a
migration before it touches real stock. A staging environment is what makes
"easily changeable" true rather than aspirational — it is the difference between
testing a catalogue change and performing it on customers.

## Suggested shape

Nothing here needs Kubernetes. A single host running the same compose file is
the right size for this shop.

- **Host:** one VPS with enough disk for `img/` to grow. Same
  `docker-compose.yml`, different `.env`.
- **Images:** on a mounted volume that is snapshotted, or moved to object
  storage with the shop reading from a CDN. Object storage is the better answer
  once the catalogue grows, because it also removes the images from the deploy
  path entirely.
- **Database:** managed MariaDB if you would rather not own backups, or the
  compose service plus a scheduled dump off-box.
- **Deploys:** pull, run pending migrations, reinstall changed modules, clear
  cache. Under a minute, and none of it touches the catalogue.
- **First deploy only:** restore a dump and an image bundle taken from the
  machine that has them. That is the honest way to move the shop you already
  have — not to rebuild it and hope the third parties agree.

## Running order, for a genuinely empty shop

Recorded because it is the thing most easily lost. This is the sequence a fresh
environment needs; the hazard in step 3 is why it cannot simply be
alphabetical.

1. `make up` — containers, PrestaShop installs itself
2. `make provision` — config, catalogue model, modules, facets, translations
3. `make purge-demo` — PrestaShop's demo catalogue, **before** real inventory
4. `make seed` — real inventory with images
5. `make seed-japanese`, `make seed-graded` — the other two catalogues
6. `make slab-frames`, `make slab-photos`, `make nav-images`, `make card-backs`,
   `make cutout-images` — generated imagery
7. `make copies-init` — serialise stock into individual copies
8. `make price-setup`, `make price-sync`, `make price-apply` — the price engine
9. `make sets-align`, `make search-index` — naming and search

Step 2 must not be re-run after step 9 without checking what it recreates.
