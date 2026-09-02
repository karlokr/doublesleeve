#!/bin/bash
# Copy the translation-autofill module into the shop and install it.
set -euo pipefail

SRC=/modules/cryptocards_i18n
DEST=/var/www/html/modules/cryptocards_i18n

if [ -d "$SRC" ]; then
    rm -rf "$DEST"
    cp -r "$SRC" "$DEST"
    chown -R www-data:www-data "$DEST"
    echo "module files copied to $DEST"
elif [ -d "$DEST" ]; then
    echo "module already in place at $DEST (baked into the image)"
else
    echo "no module at $SRC or $DEST" >&2
    exit 1
fi

# Console, not Module::install() - the PHP API path needs a Symfony container a
# bare CLI process does not have.
runuser -u www-data -- php /var/www/html/bin/console prestashop:module install cryptocards_i18n || true

runuser -u www-data -- php -r '
require_once "/var/www/html/config/config.inc.php";
$module = Module::getInstanceByName("cryptocards_i18n");
if (!$module) { fwrite(STDERR, "could not instantiate module\n"); exit(1); }
if (!Module::isEnabled("cryptocards_i18n")) { $module->enable(); echo "module enabled\n"; }
if (!$module->isRegisteredInHook("actionProductSave")) {
    $module->registerHook("actionProductSave");
    echo "hook registered: actionProductSave\n";
} else {
    echo "hook already registered: actionProductSave\n";
}
'

rm -rf /var/www/html/var/cache/* /var/www/html/themes/hummingbird/assets/cache/* 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var 2>/dev/null || true
echo "caches cleared"
