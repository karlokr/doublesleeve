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
| 18,839 generated images, 3.6 GB, most of it avoidable | `ps_html` volume | **no** |
| Search index | `meili_data` volume | no (rebuildable) |

`make backup` dumps the database. **It does not touch the 3.6 GB of images**,
which exist in exactly one place on one machine.

That 3.6 GB is also mostly waste, and worth fixing before building any backup
around it: 703 MB is originals kept at whatever resolution the CDN served (the
largest is 4.7 MB), and 2.3 GB is PrestaShop generating **twelve** sizes of
every image, several of them near-duplicates the theme never asks for. None of
it is webp. See `docs/tasks.md`, which is where the outstanding work is tracked
rather than here.

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

Four kinds of change, and only one of them travels in the image:

**Code** — modules, theme, ops scripts. Baked into the image at build time, so
deploying it is deploying a tag. Nothing is copied into a running container and
no installer runs to put files in place. *(In development this is inverted:
`devops/dev/compose.yml` bind-mounts `src/` over the baked copies so an edit is
live immediately.)*

**Schema and configuration** — attribute groups, features, facet templates,
vocabulary. Ordered idempotent scripts under `src/ops/migrations/`, applied by
`src/ops/deploy/migrate.php`, which keeps a ledger of what has run.

**Catalogue data** — sets, products, prices. Does *not* travel with a release.
It is changed in place by running an ops script against the live database,
exactly as locally: `make add-card`, `make price-sync`, `make sets-align`.

**Images** — generated once, then carried on a volume. The generators are
self-healing (`slab-photos.php` re-shoots when a frame is newer than the photo),
so they run when their source changes, not on every deploy.

## Why the image is 2.7 GB

Almost none of it is ours:

| | |
|---|---|
| `prestashop/prestashop:9.1.4-8.3` | **2.69 GB** |
| `src/ops/` | 18.7 MB |
| modules and php config | ~0.6 MB |

PrestaShop's official image ships a 1.06 GB layer from unzipping its own source,
plus the 124 MB zip that layer was made from. That is upstream's decision, and
the price of `FROM prestashop/prestashop` being a one-line upgrade.

Two things worth knowing before this looks alarming:

- **The pushed size is compressed.** 2.7 GB is the uncompressed size on disk;
  the registry stores considerably less.
- **Layers are shared.** A second release re-pushes only what changed, which is
  our ~19 MB, not the base again. The first push is the expensive one.

Getting meaningfully below this means not using PrestaShop's image: building on
`php:8.3-apache` and installing PrestaShop ourselves. That is a real project,
and it trades away the property that makes upgrades cheap.

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

That single rule is what makes redeploying the previous tag a real rollback
rather than a hope. It costs something: a change that renames or removes anything is
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

Nothing decides this by being told. The **container** works it out on start
(`devops/image/entrypoint.sh`), because in Swarm there is no step before or
after a deploy in which anyone runs anything:

- **No shop tables** means the shop has never been installed, so migrations are
  left to the provisioning pass rather than run against tables that do not exist.
- **Otherwise** it applies whatever the ledger says is pending, and a start with
  nothing pending does nothing at all.

Three details make that safe to do on every single container start:

- **Replicas would migrate concurrently.** A named lock in the database
  (`GET_LOCK('cc_migrate')`) serialises them: the first migrates, the rest wait
  and then find nothing to do. The lock frees itself if a container dies, so a
  killed task cannot wedge the next deploy.
- **A failed migration logs loudly and still starts.** Taking the service down
  on a bad migration turns it into an outage; instead the old tasks keep serving
  because Swarm only replaces one that comes up healthy.
- **`CC_MIGRATE=0`** on the cron service, so only the web container migrates.

### What a deploy actually does

```bash
APP_IMAGE_TAG=v1.2.4 docker stack deploy -c devops/prod/stack.yml doublesleeve
```

Swarm pulls the image, starts a new task **before** stopping the old one
(`order: start-first`), waits for its healthcheck, and on failure puts the
previous task back (`failure_action: rollback`). The healthcheck has a generous
`start_period` because a first start migrates before it serves.

## Releases are cut by hand

There is no CI, deliberately. A hosted runner was never allocated to the GitHub
repository, which is a billing matter rather than an engineering one; a
self-hosted GitLab runner would have worked, but standing one up and maintaining
it to serve a single-developer project is more infrastructure than the problem
deserves.

Nothing about the artifact changes. The version is still semantic, the image
still carries three tags, the release is still a real GitHub release with the
deploy and rollback commands in it. The only difference is which machine types
the command:

```bash
make release              # patch: v1.2.3 -> v1.2.4
make release BUMP=minor   # v1.2.3 -> v1.3.0
make release BUMP=major   # v1.2.3 -> v2.0.0
make release BUMP=v2.5.0  # exactly that
make release-dry          # say what would happen, do none of it
```

`devops/release.sh` works out the next version from the tags, builds, pushes to
GHCR, tags the commit and publishes the release.

**Every check runs before the build**, because a half-published release is worse
than no release: a dirty tree, a HEAD that does not match `origin`, a tag that
already exists, `gh` not logged in. And the git tag is created **after** the
image is pushed, so a release can never name an image that does not exist.

GHCR takes the token `gh` already holds, so there is no second credential to
create or store anywhere.

### The three tags, and which one production pins to

| tag | answers |
|---|---|
| `v1.2.3` | what a human deploys |
| the commit sha | what is *actually* running, traceable to an exact tree |
| `latest` | convenience only |

Production pins to the **version**. Pinning to `latest` would make a redeploy
mean something different depending on when it happened, which is the property
that makes rollback meaningless.

## Suggested shape

A Swarm stack on a single node is the right size for this shop, and the stack
file is `devops/prod/stack.yml`. Swarm is worth it here not for scale but for
what it does to a deploy: `start-first` plus a healthcheck plus
`failure_action: rollback` means changing a tag is safe on its own, with no
script and no one watching it.

- **Host:** one node with enough disk for `img/` to grow, labelled once:

  ```bash
  docker node update --label-add doublesleeve=true <node>
  ```

  Every service is constrained to that label, because local volumes do not
  follow a task to another node — without it a reschedule comes up against
  empty volumes. A missing label leaves tasks pending, which is the failure you
  want: visible, rather than a shop that returns with no images. Raising
  replicas or adding a node means moving those volumes to shared storage first,
  so this is the constraint to revisit deliberately rather than delete.
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
