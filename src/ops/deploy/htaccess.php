<?php
/**
 * Regenerate /var/www/html/.htaccess.
 *
 * Friendly URLs - /11-singles, /content/6-pokemon-sets - are Apache rewrites,
 * and the rules live in a .htaccess that PrestaShop writes into the web root.
 * Without it Apache never hands the request to PHP at all, so every link off
 * the homepage is a bare Apache 404 while the homepage itself works fine.
 *
 * This has to run on EVERY container start, not once at install, because the
 * web root is not a volume in production: the container is replaced wholesale
 * on each deploy and comes back from the image, which has no .htaccess. Writing
 * it once would survive exactly until the next redeploy.
 *
 * Cheap to repeat - it rewrites one small file.
 */
require_once '/var/www/html/config/config.inc.php';

// The setting the rules are generated from. Without it PrestaShop emits an
// .htaccess with no rewrite block, which fails in exactly the same way.
if (!Configuration::get('PS_REWRITING_SETTINGS')) {
    Configuration::updateValue('PS_REWRITING_SETTINGS', 1);
    echo "   + friendly URLs enabled\n";
}

if (!Tools::generateHtaccess(null, 1)) {
    fwrite(STDERR, "   ! could not write .htaccess\n");
    exit(1);
}

$rules = substr_count((string) @file_get_contents(_PS_ROOT_DIR_ . '/.htaccess'), 'RewriteRule');
echo "   + .htaccess written ($rules rewrite rules)\n";
