#!/bin/bash
#
# Build the whole shop, from an installed-but-empty PrestaShop to a storefront
# with catalogue, imagery, prices and search.
#
# Runs once, automatically, on a first deploy: the base image executes
# everything in /tmp/post-install-scripts/ after its installer succeeds, and
# devops/image/post-install/10-bootstrap.sh calls this. Nobody types anything.
#
# Re-runnable by hand if a step fails, because every script it calls is
# idempotent:
#
#   docker exec <shop> bash /provisioning/deploy/bootstrap.sh
#   docker exec <shop> bash /provisioning/deploy/bootstrap.sh --from 5
#
# ORDER IS LOAD-BEARING and is not alphabetical. Four hazards it encodes, each
# of which has actually broken a deploy:
#
#   - purge-demo must run BEFORE inventory. It deletes every product.
#   - set taxonomy must run BEFORE inventory. The seeds file each card into its
#     set category by name; without the sets, every single is rejected with
#     "unknown set category" and the catalogue comes up empty.
#   - the USD/CAD rate must exist BEFORE inventory. Every seed converts source
#     prices to CAD and refuses to guess.
#   - setup.php (step 2) recreates taxonomy the align scripts (step 4) delete,
#     so it must never run after them. That has resurrected retired data twice.
#   - copies must be serialised AFTER sku-rebuild. A copy belongs to a
#     combination, so rebuilding combinations under existing copies orphans
#     them and the shop holds stock it cannot hand to a buyer.
#
# This is not hermetic: it fetches from tcgcsv, pokemontcg.io, the Bulbagarden
# Archives, PriceCharting and the Bank of Canada. That is fine and expected -
# it happens once, on a shop with no orders to lose.
set -uo pipefail

FROM_STEP=1
[ "${1:-}" = "--from" ] && FROM_STEP="${2:-1}"

php_run()  { runuser -u www-data -- php -d memory_limit=-1 "/provisioning/$1" "${@:2}"; }
bash_run() { bash "/provisioning/$1" "${@:2}"; }

STEP=0
FAILED=()
# Tracked apart from FAILED on purpose - see the baseline block at the bottom.
MIGRATIONS_FAILED=0

step() {
    STEP=$((STEP + 1))
    if [ "$STEP" -lt "$FROM_STEP" ]; then
        printf '\n[bootstrap] %d/%d  %s (skipped)\n' "$STEP" 12 "$1"
        return 1
    fi
    printf '\n[bootstrap] ======== %d/%d  %s\n' "$STEP" 12 "$1"
    return 0
}

run() {
    local what="$1"; shift
    printf '[bootstrap]   -> %s\n' "$what"
    if ! "$@"; then
        printf '[bootstrap]   !! FAILED: %s\n' "$what"
        FAILED+=("step $STEP: $what")
        case "$*" in *migrations/*) MIGRATIONS_FAILED=1 ;; esac
    fi
}

# The base image runs /tmp/post-install-scripts/ whether its installer
# succeeded or FAILED. Without this check a failed install produces 37
# identical _DB_PREFIX_ errors that bury the one line that actually matters.
if [ ! -f /var/www/html/app/config/parameters.php ]; then
    printf '\n[bootstrap] app/config/parameters.php does not exist, so PrestaShop\n'
    printf '[bootstrap] is NOT installed. The installer failed - scroll up for its\n'
    printf '[bootstrap] error. Refusing to run: every step below would fail the same\n'
    printf '[bootstrap] way and say nothing useful.\n'
    exit 1
fi

printf '[bootstrap] building the shop. this takes a while - it fetches the\n'
printf '[bootstrap] catalogue from upstream and generates every image.\n'

# 1 ------------------------------------------------------------------------
# The installer left the back office at admin-dev because PrestaShop hardcodes
# that name for `assets:install` and aborts on anything else. Move it to the
# real name now that the install is done.
if step "admin folder"; then
    run "rename admin-dev -> ${CC_ADMIN_FOLDER:-admin}" \
        bash_run setup/rename-admin.sh "${CC_ADMIN_FOLDER:-admin}"
fi

# 2 ------------------------------------------------------------------------
# Config, catalogue model, modules, facets, translations. MUST precede the
# align scripts in step 4 - setup.php recreates taxonomy they delete.
if step "configuration and catalogue model"; then
    run "shop configuration"      php_run setup/setup.php
    run "facets"                  php_run setup/facets.php
    run "storefront"              php_run setup/storefront.php
    run "cms pages"               php_run setup/pages.php
    run "search module"           bash_run installers/install-search-module.sh
    run "theme module"            bash_run installers/install-theme-module.sh
    run "copies module"           bash_run installers/install-copies-module.sh
    run "i18n module"             bash_run installers/install-i18n-module.sh
    run "card language axis"      php_run migrations/card-language.php
    run "grader axis"             php_run migrations/grader-axis.php
    run "retire print region"     php_run migrations/retire-print-region.php
    run "retire ungraded graders" php_run migrations/retire-ungraded-graders.php
    run "sealed card language"    php_run migrations/sealed-card-language.php
    run "translations"            php_run setup/translations.php
fi

# 3 ------------------------------------------------------------------------
# Before any real inventory exists, because it deletes every product.
if step "delete PrestaShop's demo catalogue"; then
    run "purge demo" php_run setup/purge-demo.php
fi

# 4 ------------------------------------------------------------------------
# BEFORE inventory. The seeds file each card into its set category by name, and
# without these the whole singles catalogue lands nowhere: "unknown set
# category: Base (BS)" repeated a few hundred times and zero products created.
#
# Safe here rather than earlier because these DELETE taxonomy that setup.php
# recreates - so they must follow step 2, and setup.php must never run again
# after them.
if step "set taxonomy"; then
    run "tcgplayer sets"    php_run catalog/sets-tcgplayer.php
    run "tcgplayer sets jp" php_run catalog/sets-tcgplayer.php Japanese
    run "collectr names"    php_run catalog/align-collectr.php
    run "facets"         php_run setup/facets.php
    run "cms pages"      php_run setup/pages.php
fi

# 5 ------------------------------------------------------------------------
# Also BEFORE inventory. Every seed converts USD source prices into CAD and
# refuses to guess: no rate in price_fx means no products. price-sync caches the
# Bank of Canada rate even on a dry run, which is all that is needed here -
# there is nothing to reprice yet.
if step "price engine and the USD/CAD rate"; then
    run "price schema"   php_run setup/price-schema.php
    run "fetch fx rate"  php_run pricing/price-sync.php
    run "currency rates" php_run pricing/currency-sync.php
fi

# 6 ------------------------------------------------------------------------
if step "real inventory"; then
    run "singles and sealed" php_run inventory/seed-inventory.php
    run "japanese"           php_run inventory/seed-japanese.php
    run "shadowless"         php_run inventory/seed-shadowless.php
    run "graded slabs"       php_run inventory/seed-graded.php
fi

# 7 ------------------------------------------------------------------------
# Printings, rarities and card data normalised against TCGplayer. Everything
# downstream - titles, SKUs, facets - reads this vocabulary.
if step "align vocabularies"; then
    run "tcgplayer alignment" php_run catalog/align-tcgplayer.php
    # --rehome is not a repair option, it is the only thing that files each
    # product under its set AND stamps the Set feature. Without it the Set facet
    # does not exist - "missing features skipped: Set" - and the storefront
    # reports zero sets represented while holding a thousand cards.
    # Products have to exist first, so it cannot live with the taxonomy in step 4.
    run "re-home western"  php_run catalog/sets-tcgplayer.php Western --rehome
    run "re-home japanese" php_run catalog/sets-tcgplayer.php Japanese --rehome
fi

# 8 ------------------------------------------------------------------------
# The slow part, and where the disk goes.
if step "imagery"; then
    run "category images"  php_run media/seed-category-images.php
    run "cutouts"          php_run media/cutout-images.php
    run "card backs"       php_run media/backfill-card-backs.php
    run "slab photos"      php_run media/slab-photos.php
    run "nav artwork"      php_run media/seed-nav-images.php
    run "storefront tiles" php_run setup/storefront.php
fi

# 9 ------------------------------------------------------------------------
# Now there is stock to price.
if step "prices"; then
    run "sync and apply" php_run pricing/price-sync.php --apply
fi

# 10 -----------------------------------------------------------------------
# Base Set printings, then SKU rebuild, then titles - derive-names reads the
# printing and set names those two settle.
if step "naming and SKUs"; then
    run "base set unlimited" php_run migrations/base-set-unlimited.php
    run "rebuild SKUs"       php_run catalog/sku-rebuild.php
    run "derive titles"      php_run catalog/derive-names.php
fi

# 11 -----------------------------------------------------------------------
# AFTER sku-rebuild, not before. A copy is serialised against a combination, so
# rebuilding combinations underneath existing copies orphans them - the shop
# then has stock it cannot hand to a buyer.
#
# seed-photos is what the copy picker actually displays. Without it copies exist
# but every one is pictureless, and choosing a card by photograph - the whole
# point of serialising - has nothing to show.
if step "serialised copies"; then
    run "copies schema" php_run setup/copies-schema.php
    run "copy depth"    php_run inventory/seed-copy-depth.php
    run "copy photos"   php_run inventory/seed-photos.php
fi

# 12 -----------------------------------------------------------------------
# Last: both read the finished catalogue.
if step "facets and search index"; then
    run "facets"       php_run setup/facets.php
    run "search index" php_run catalog/search-index.php
    # The entrypoint does this on every start too; here so a first install
    # serves friendly URLs without waiting for a restart.
    run "htaccess"     php_run deploy/htaccess.php
fi

# --------------------------------------------------------------------------
# Everything under migrations/ has just been applied as part of building the
# shop, so record them as applied rather than leaving the next container start
# to run them again against a shop that already has them. This also creates the
# ledger table, which is what tells the entrypoint the shop is ready.
#
# Gated on the MIGRATION steps only, deliberately - not on ${#FAILED[@]}.
# Requiring a clean run of all forty-odd scripts made an unrelated upstream
# hiccup - a language pack that would not download - permanently prevent the
# ledger from existing, and with no ledger the entrypoint skips migrations on
# every future deploy. A cosmetic failure must not disable the migration system.
if [ "$MIGRATIONS_FAILED" = "0" ]; then
    printf '\n[bootstrap] ======== baselining the migration ledger\n'
    run "baseline" php_run deploy/migrate.php --baseline
else
    printf '\n[bootstrap] !! a migration failed, so the ledger is NOT baselined.\n'
    printf '[bootstrap] !! migrations stay disabled until this is fixed and\n'
    printf '[bootstrap] !! bootstrap is re-run. That is deliberate: baselining\n'
    printf '[bootstrap] !! now would record a migration that never applied.\n'
fi

printf '\n[bootstrap] ========================================\n'
if [ ${#FAILED[@]} -eq 0 ]; then
    printf '[bootstrap] shop built.\n'
    exit 0
fi

printf '[bootstrap] built, with %d failure(s):\n' "${#FAILED[@]}"
printf '[bootstrap]   %s\n' "${FAILED[@]}"
printf '[bootstrap]\n'
printf '[bootstrap] Every step is idempotent, so re-run from the one that\n'
printf '[bootstrap] failed rather than starting over:\n'
printf '[bootstrap]   bash /provisioning/deploy/bootstrap.sh --from N\n'
exit 1
