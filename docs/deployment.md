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
| 18,839 generated images, 3.6 GB — see gap 1, most of it avoidable | `ps_html` volume | **no** |
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
and most of the machinery already exists — every script under `src/ops/migrations/`
is written to be idempotent and to refuse to damage stock it did not expect.

The consequence worth being explicit about: **once the shop takes its first
order, production's database is the source of truth**, and the repo's job is to
change it safely rather than to reproduce it.

## What deploys, and how

Four kinds of change, three different mechanisms:

**Code** — modules, theme, ops scripts. Ships from git. `src/modules/` is a
read-only bind mount and the shop runs a *copy*, so a deploy is
`git pull` plus the installer for whatever changed:

```bash
docker exec cryptocards-shop bash /provisioning/installers/install-theme-module.sh
docker exec -u www-data cryptocards-shop rm -rf /var/www/html/var/cache/prod
```

**Schema and configuration** — attribute groups, features, facet templates,
vocabulary. Ships as ordered idempotent scripts under `src/ops/setup/` and
`src/ops/migrations/`. This is the part that needs a ledger (below).

**Catalogue data** — sets, products, prices. Does *not* ship. It is changed in
place by running an ops script against the live database, exactly as it is
locally: `make add-card`, `make price-sync`, `make sets-align`.

**Images** — generated once, then carried. The generators are already
self-healing (`slab-photos.php` re-shoots when a frame is newer than the photo),
so they run when their source changes, not on every deploy.

## The four gaps, worst first

### 1. The images are 3.6 GB, and most of that is waste

That number is not normal, and it is worth knowing why before backing it up:

| | files | size |
|---|---|---|
| originals | 797 | 703 MB |
| generated thumbnails | 9,564 | 2.3 GB |

- **Originals are stored at source resolution.** The largest is 4.7 MB; the
  average is 880 KB. Scans are fetched from the TCGplayer CDN and never
  downscaled. A card scan needs about 1000px on its long edge.
- **PrestaShop generates twelve sizes of every image.**
  `cart/small/medium/large/home_default`, `default_xs/sm/md/lg/xl`, and
  `product_main` plus `product_main_2x` at 1440². Several are near-duplicates
  the theme never requests. 752 image rows x 12 is the 9,564 files.
- **Nothing is webp.** Every one of those files is a JPEG.

Capping originals at intake, pruning `image_type` to the sizes the theme
actually asks for, and enabling webp should take this to a few hundred
megabytes. Do that *before* building a backup process around it — there is no
sense engineering the transport of 3.6 GB that ought to be 400 MB.

Then extend `make backup`, which is database-only today, to carry `img/` as
well. Until it does, the imagery exists in exactly one place on one machine, and
losing it means re-fetching thousands of scans from third parties and
recompositing every slab.

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

## The deployable artifact: our own image

Today `devops/dev/compose.yml` runs `prestashop/prestashop:${PS_VERSION}` and our
work sits *beside* it as bind mounts — `./ops:/provisioning`, `./modules:/modules`
— with an installer copying modules into the running container. That is right
for development and wrong for deployment, because it means **no artifact
anywhere is "PrestaShop and our shop"**. There is nothing to version, pull or
roll back.

Build one:

```dockerfile
FROM prestashop/prestashop:9.1.4
COPY src/modules/ /var/www/html/modules/
COPY src/ops/     /provisioning/
```

Everything Docker is supposed to give you then does:

- deploy is `docker compose pull && docker compose up -d`
- **upgrading PrestaShop is a one-line `FROM` bump**, rebuild, redeploy
- rollback is deploying the previous tag
- CI builds and tags on every push to `main`
- development keeps the bind mounts, so editing is still instant

### The catch, and it is the whole trick

PrestaShop's image expects `/var/www/html` to be writable, and the current
compose file mounts a volume over **all** of it. A volume over that path hides
everything baked into the image — bake the theme in and the volume covers it.

So the volume has to shrink to the paths that actually hold state:

| path | why it persists |
|---|---|
| `img/` | uploaded and generated imagery |
| `var/` | cache and logs |
| `config/`, `app/config/parameters.php` | the installed shop's DB credentials |
| `upload/`, `download/` | customer-supplied and digital-product files |

Everything else — core, modules, theme — comes from the image and is replaced
wholesale on deploy. That is what makes a deploy atomic and a rollback real.

It also removes a step: `install-theme-module.sh` currently *copies* the module
into place, which is only necessary because the module lives outside the image.
Once it is baked in, the installer has nothing to copy and only needs to
register hooks — a database operation, which is to say a migration.

## The upgrade contract

This is the part that makes a version bump safe, and it is a rule about how
migrations are written rather than anything a script can enforce.

**Deploying an older image does not undo a migration.** Code rolls back for
free — it is just a different tag. The database does not. So "safe" cannot mean
"reversible"; it has to mean that going backwards was never dangerous in the
first place:

> **Migrations are forward-only and backward-compatible.** After release N+1's
> migrations have run, release N's code must still work against the database.

That single rule is what makes `deploy.sh <previous tag>` a real rollback rather
than a hope. It costs something: a change that renames or removes anything is
split across two releases.

| | release N+1 | release N+2, after N+1 is retired everywhere |
|---|---|---|
| add a column | add it, nullable; old code ignores it | — |
| rename a column | add the new one, write both, read the new | drop the old one |
| remove a value | stop writing it, leave it in place | delete it |
| change a meaning | add a new field; leave the old one alone | drop the old field |

The things that break a rollback, and so are never done in one release: dropping
or renaming a column the previous image still reads, deleting an attribute value
that products still reference, or changing what a column means in place.

`ops/migrations/retire-ungraded-graders.php` is the shape to copy — it refuses
to delete any grader that has combinations, so it cannot remove something the
running code depends on.

### First run versus upgrade

`devops/prod/deploy.sh` decides which it is by asking the **database**, not by
reading a marker file or being told:

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE '%_shop'
```

No shop tables means an install: PrestaShop installs itself, the setup scripts
run, and the migrations that shipped in that image are **baselined** — recorded
as applied without being replayed against a shop `setup.php` has just built
correctly.

Anything else is an upgrade, and an upgrade runs exactly one thing: the
migrations the new image brought that this database has not seen. They run in a
one-shot container of the new image, so a failure never leaves a half-started
web container serving traffic.

### What each deploy does, in order

1. **Pull first.** A bad tag or an unreachable registry fails while the old
   version is still serving.
2. **Back up the database.** It is the only thing that cannot be restored by
   redeploying.
3. **Migrate** in a one-shot container, forward-only, ledger-recorded.
4. **Start** the new containers.
5. **Health check**, and on failure print the exact rollback command.

## The release, and where versions come from

A pull request merged into `production` builds the image, tags it, and cuts a
GitHub release. The version comes from a **label on the pull request** —
`major`, `minor`, or nothing for a patch — rather than from parsing commit
subjects. A label is a deliberate act, visible before the merge, and it does not
change meaning because of how someone phrased a commit.

Images carry three tags: the version (what you deploy), the commit sha (what
makes a running container traceable to an exact tree), and `latest`.

## Suggested shape

Nothing here needs Kubernetes. A single host running the same compose file is
the right size for this shop.

- **Host:** one VPS with enough disk for `img/` to grow. Same compose file,
  different `.env`, our image tag instead of PrestaShop's.
- **Registry:** GitHub Container Registry, since the repo is already there.
- **Images:** on a snapshotted volume, or object storage behind a CDN once the
  catalogue grows — that also takes them out of the deploy path entirely.
- **Database:** managed MariaDB, or the compose service plus a dump off-box.
- **First deploy only:** restore a dump and an image bundle from the machine
  that has them. That is how you move the shop you already have, rather than
  rebuilding it and hoping five third parties agree.

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
