#!/bin/bash
# Rename the admin folder away from the install-time name.
#
# The install MUST run with the folder named "admin-dev" (Install.php hardcodes
# that name when installing bundle assets), but leaving it there in production is
# bad practice - a predictable admin path invites automated login attacks.
set -euo pipefail

TARGET="${1:?usage: rename-admin.sh <new-admin-folder>}"
ROOT=/var/www/html

if [ -d "$ROOT/$TARGET" ]; then
    echo "admin folder already '$TARGET'"
    exit 0
fi

if [ ! -d "$ROOT/admin-dev" ]; then
    echo "!! neither '$TARGET' nor 'admin-dev' found under $ROOT" >&2
    exit 1
fi

mv "$ROOT/admin-dev" "$ROOT/$TARGET"
chown -R www-data:www-data "$ROOT/$TARGET"
echo "admin folder renamed: admin-dev -> $TARGET"

# The compiled Symfony container hardcodes absolute admin paths (Twig looks for
# <admin>/themes/new-theme). Leaving the old cache in place makes every back
# office page return 500 after the rename.
rm -rf "$ROOT/var/cache/"*
echo "symfony cache cleared (rebuilds on next request)"
