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
# ORDER IS LOAD-BEARING and is not alphabetical. Two hazards it encodes:
#
#   - purge-demo must run BEFORE real inventory. It deletes every product, so
#     running it later would delete the catalogue this builds.
#   - setup.php (step 2) recreates taxonomy that the align scripts (step 9)
#     delete. It must never run after them. That has resurrected retired data
#     twice; it is why this file exists rather than a README someone follows.
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

step() {
    STEP=$((STEP + 1))
    if [ "$STEP" -lt "$FROM_STEP" ]; then
        printf '\n[bootstrap] %d/%d  %s (skipped)\n' "$STEP" 9 "$1"
        return 1
    fi
    printf '\n[bootstrap] ======== %d/%d  %s\n' "$STEP" 9 "$1"
    return 0
}

run() {
    local what="$1"; shift
    printf '[bootstrap]   -> %s\n' "$what"
    if ! "$@"; then
        printf '[bootstrap]   !! FAILED: %s\n' "$what"
        FAILED+=("step $STEP: $what")
    fi
}

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
# align scripts in step 9 - setup.php recreates taxonomy they delete.
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
if step "real inventory"; then
    run "singles and sealed" php_run inventory/seed-inventory.php
    run "japanese"           php_run inventory/seed-japanese.php
    run "shadowless"         php_run inventory/seed-shadowless.php
    run "graded slabs"       php_run inventory/seed-graded.php
fi

# 5 ------------------------------------------------------------------------
# The slow part, and where the disk goes.
if step "imagery"; then
    run "category images"  php_run media/seed-category-images.php
    run "cutouts"          php_run media/cutout-images.php
    run "card backs"       php_run media/backfill-card-backs.php
    run "slab photos"      php_run media/slab-photos.php
    run "nav artwork"      php_run media/seed-nav-images.php
    run "storefront tiles" php_run setup/storefront.php
fi

# 6 ------------------------------------------------------------------------
if step "serialised copies"; then
    run "copies schema" php_run setup/copies-schema.php
fi

# 7 ------------------------------------------------------------------------
if step "prices"; then
    run "price schema"    php_run setup/price-schema.php
    run "currency rates"  php_run pricing/currency-sync.php
    run "sync and apply"  php_run pricing/price-sync.php --apply
fi

# 8 ------------------------------------------------------------------------
# Base Set printings, then SKU rebuild, then titles - derive-names reads the
# printing and set names those two settle.
if step "naming and SKUs"; then
    run "base set unlimited" php_run migrations/base-set-unlimited.php
    run "rebuild SKUs"       php_run catalog/sku-rebuild.php
    run "derive titles"      php_run catalog/derive-names.php
fi

# 9 ------------------------------------------------------------------------
# Last, because these DELETE taxonomy that setup.php would recreate.
if step "set taxonomy and search index"; then
    run "tcgplayer sets" php_run catalog/sets-tcgplayer.php
    run "collectr names" php_run catalog/align-collectr.php
    run "facets"         php_run setup/facets.php
    run "cms pages"      php_run setup/pages.php
    run "search index"   php_run catalog/search-index.php
fi

# --------------------------------------------------------------------------
# Everything under migrations/ has just been applied as part of building the
# shop, so record them as applied rather than leaving the next container start
# to run them again against a shop that already has them. This also creates the
# ledger table, which is what tells the entrypoint the shop is ready.
if [ ${#FAILED[@]} -eq 0 ]; then
    printf '\n[bootstrap] ======== baselining the migration ledger\n'
    run "baseline" php_run deploy/migrate.php --baseline
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
