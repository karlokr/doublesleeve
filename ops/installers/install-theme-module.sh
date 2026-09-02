#!/bin/bash
# Copy the design-system module into the shop and install it.
set -euo pipefail

SRC=/modules/cryptocards_theme
DEST=/var/www/html/modules/cryptocards_theme

rm -rf "$DEST"
cp -r "$SRC" "$DEST"
chown -R www-data:www-data "$DEST"
echo "theme module copied to $DEST"

# Install through the Symfony console, not Module::install(). The PHP API path
# calls Language::updateModulesTranslations(), which needs a container that a bare
# CLI process does not have - it fatals on "Call to a member function get() on null"
# even if you boot AdminKernel by hand.
runuser -u www-data -- php /var/www/html/bin/console prestashop:module install cryptocards_theme || true

runuser -u www-data -- php -r '
require_once "/var/www/html/config/config.inc.php";
$module = Module::getInstanceByName("cryptocards_theme");
if (!Module::isEnabled("cryptocards_theme")) { $module->enable(); echo "module enabled\n"; }
// Assert the hook even after a partial install, otherwise no assets load at all.
foreach (["displayHeader", "displayProductListReviews", "displayCartExtraProductInfo", "displayCartExtraProductActions", "displayHeaderCategory", "actionMainMenuModifier"] as $hook) {
    if (!$module->isRegisteredInHook($hook)) {
        $module->registerHook($hook);
        echo "hook registered: $hook\n";
    } else {
        echo "hook already registered: $hook\n";
    }
}
'

rm -rf /var/www/html/var/cache/* /var/www/html/themes/hummingbird/assets/cache/* 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var 2>/dev/null || true
echo "caches cleared"
