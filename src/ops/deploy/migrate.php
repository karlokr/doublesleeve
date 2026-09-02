<?php
/**
 * Applies pending migrations, and records what it applied.
 *
 * Deploys carry the database forward; they never rebuild it. Once the shop has
 * taken an order there is no rebuild available at any price, so the only
 * question a deploy can ask is "what has not run yet" - and until now nothing
 * knew. Every migration is idempotent, so the fallback was to run them all,
 * which works and gets slower and more frightening as the list grows.
 *
 *   migrate.php              apply everything pending
 *   migrate.php --dry        list what would run, change nothing
 *   migrate.php --baseline   record every migration as applied WITHOUT running
 *                            it, for a database that already has them
 *
 * ORDER IS FILENAME ORDER. New migrations should be prefixed with the date -
 * `2026-08-15-sealed-card-language.php` - so that order is total and obvious.
 * The ones that predate this runner are unprefixed and are baselined rather
 * than sorted, because they have already run everywhere they exist.
 *
 * A migration that has been applied is never run again, and if its contents
 * have changed since it was applied the run STOPS. Editing an applied migration
 * means production and the repository disagree about what the database is, and
 * the only safe move is for a human to look.
 */
declare(strict_types=1);

require_once '/var/www/html/config/config.inc.php';

const MIGRATION_DIR = '/provisioning/migrations/';

$dry = in_array('--dry', $argv ?? [], true);
$baseline = in_array('--baseline', $argv ?? [], true);

function line(string $s): void { echo "   + $s\n"; }
function warn(string $s): void { echo "   ! $s\n"; }
function fail(string $s): never { echo "   ! $s\n"; exit(1); }

echo "\n\033[1m== Migrations\033[0m\n";

$db = Db::getInstance();

/**
 * The ledger creates itself. A deploy that needed a human to have run a schema
 * step first would be the same problem one level down.
 */
$db->execute(
    'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'migration (
        filename VARCHAR(191) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL,
        PRIMARY KEY (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = [];
foreach ($db->executeS('SELECT filename, checksum FROM ' . _DB_PREFIX_ . 'migration') ?: [] as $row) {
    $applied[(string) $row['filename']] = (string) $row['checksum'];
}

$files = glob(MIGRATION_DIR . '*.php') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    warn('no migrations found at ' . MIGRATION_DIR);
    exit(0);
}

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    $checksum = hash_file('sha256', $path);

    if (!isset($applied[$name])) {
        $pending[] = [$name, $path, $checksum];
        continue;
    }

    /**
     * Applied, but not the same file. Not a warning - a stop. The database was
     * changed by something other than what is in the repository now, and
     * guessing which of the two is right is exactly the judgement a deploy
     * should never make on its own.
     */
    if ($applied[$name] !== $checksum) {
        fail($name . ' has changed since it was applied - refusing to continue');
    }
}

if ($pending === []) {
    line('nothing pending (' . count($applied) . ' already applied)');
    exit(0);
}

foreach ($pending as [$name, $path, $checksum]) {
    if ($dry) {
        line('would apply ' . $name);
        continue;
    }

    if ($baseline) {
        // Recorded, deliberately unrun: this database already has it.
        $db->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'migration (filename, checksum, applied_at)
             VALUES ("' . pSQL($name) . '", "' . pSQL($checksum) . '", NOW())'
        );
        line('baselined ' . $name);
        continue;
    }

    line('applying ' . $name);
    $output = [];
    $status = 0;
    // A separate process per migration: they are standalone scripts that boot
    // their own kernel, and one of them fataling must not take the runner with
    // it - the ledger has to survive to record what did succeed.
    exec('php ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    foreach ($output as $out) {
        echo '     ' . $out . "\n";
    }
    if ($status !== 0) {
        fail($name . ' failed with status ' . $status . ' - stopping, later migrations not run');
    }

    $db->execute(
        'INSERT INTO ' . _DB_PREFIX_ . 'migration (filename, checksum, applied_at)
         VALUES ("' . pSQL($name) . '", "' . pSQL($checksum) . '", NOW())'
    );
}

line($dry ? count($pending) . ' pending' : 'done');
