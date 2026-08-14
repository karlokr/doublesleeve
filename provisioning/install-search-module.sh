#!/bin/bash
# Copy the instant-search module into the shop and install it.
#
# The module lives in provisioning/ (a bind mount, so it is versioned with the
# project) and is copied into /var/www/html/modules, which is a Docker volume.
set -euo pipefail

SRC=/provisioning/module/cryptocards_search
DEST=/var/www/html/modules/cryptocards_search

rm -rf "$DEST"
cp -r "$SRC" "$DEST"
chown -R www-data:www-data "$DEST"
echo "module files copied to $DEST"

# Install through the Symfony console: Module::install() calls
# Language::updateModulesTranslations(), which needs a container a bare CLI process
# does not have, and fatals even if you boot AdminKernel by hand.
runuser -u www-data -- php /var/www/html/bin/console prestashop:module install cryptocards_search || true

runuser -u www-data -- php -r '
require_once "/var/www/html/config/config.inc.php";
$module = Module::getInstanceByName("cryptocards_search");
if (!$module) { fwrite(STDERR, "could not instantiate module\n"); exit(1); }
if (!Module::isEnabled("cryptocards_search")) { $module->enable(); echo "module enabled\n"; }

// Always (re)assert the hook. A half-completed install can leave the module row
// present while registerHook() never ran, which silently loads no assets at all.
if (!$module->isRegisteredInHook("displayHeader")) {
    $module->registerHook("displayHeader");
    echo "displayHeader hook registered\n";
} else {
    echo "displayHeader hook already registered\n";
}
'

# The theme's asset cache holds the compiled JS/CSS bundles. Clearing only
# var/cache leaves stale module assets being served to browsers.
rm -rf /var/www/html/var/cache/* /var/www/html/themes/hummingbird/assets/cache/* 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var 2>/dev/null || true
echo "caches cleared (app + theme assets)"
