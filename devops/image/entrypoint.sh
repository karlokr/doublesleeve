#!/bin/sh
#
# Applies pending migrations, then hands over to PrestaShop's own entrypoint.
#
# This exists because production is a Swarm stack. Upgrading is changing an
# image tag and redeploying; nobody execs into a container to run a step
# afterwards, and there is no "run this once before the service starts" in
# Swarm. So the container migrates itself, on every start, and the ledger is
# what makes that cheap: a start with nothing pending does nothing.
#
# Three things have to be true for that to be safe, and each is handled below.
set -e

log() { printf '[entrypoint] %s\n' "$1"; }

: "${DB_SERVER:=db}"
: "${DB_PORT:=3306}"
: "${CC_MIGRATE:=1}"
: "${ADMIN_PASSWD:=}"

# The back office. PrestaShop's installer hardcodes `assets:install admin-dev`
# and aborts on any other name, so the install must happen with admin-dev and
# be renamed afterwards. Keep one user-facing variable and handle that here:
# bootstrap.sh renames it after installing, and every later start renames it
# again, because admin/ is not a volume and arrives fresh from the image.
export CC_ADMIN_FOLDER="${PS_FOLDER_ADMIN:-admin}"
export PS_FOLDER_ADMIN=admin-dev

if [ "$CC_MIGRATE" != "1" ]; then
    log "CC_MIGRATE=$CC_MIGRATE, skipping migrations"
    exec docker-php-entrypoint /tmp/docker_run.sh
fi

# 1. The database has to be there. Swarm starts services in whatever order it
#    likes and restarts them independently, so depends_on means nothing here.
log "waiting for $DB_SERVER:$DB_PORT"
i=0
while [ "$i" -lt 60 ]; do
    if mysql -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWD" -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if [ "$i" -ge 60 ]; then
    log "database never became reachable, starting anyway so the container can report why"
    exec docker-php-entrypoint /tmp/docker_run.sh
fi

# 2. A shop that has never been installed has nothing to migrate. PrestaShop's
#    own entrypoint installs it when PS_INSTALL_AUTO=1; migrating first would
#    run against tables that do not exist yet.
INSTALLED=$(mysql -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWD" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='$DB_NAME' AND table_name LIKE '%_shop'" 2>/dev/null || echo 0)

if [ "${INSTALLED:-0}" = "0" ]; then
    # Decide this here rather than trusting the stack file. Whether the shop
    # exists is a fact about the database, and this has already failed once by
    # deferring to a PS_INSTALL_AUTO that a stale copy of the stack still had
    # set to 0: the installer silently never ran, and the 37 scripts after it
    # all failed on a shop that was never created.
    export PS_INSTALL_AUTO=1
    export PS_ERASE_DB=0
    export PS_INSTALL_DB=0
    log "no shop tables: installing PrestaShop, then bootstrap builds the shop"

    if [ "${#ADMIN_PASSWD}" -lt 8 ]; then
        log "!! ADMIN_PASSWD is ${#ADMIN_PASSWD} characters and PrestaShop needs 8."
        log "!! The install WILL fail. Set ADMIN_PASSWORD in the stack environment."
    fi

    exec docker-php-entrypoint /tmp/docker_run.sh
fi

# ---------------------------------------------------------------------------
# Everything from here to the migration gate has to happen on EVERY start of an
# installed shop, because none of it survives a deploy: the web root is not a
# volume, so the container comes back from the image each time. None of it has
# anything to do with migrations, and putting it after the ledger check meant a
# shop that had never been bootstrapped got none of it - which is exactly how
# .htaccess stayed missing and every link 404'd on a container that was
# otherwise healthy.

# The installer ships in the base image and is only ever renamed by its
# entrypoint, never removed - so /install/ comes back on every deploy and is
# publicly reachable. On an installed shop it has no job left.
rm -rf /var/www/html/install /var/www/html/install.lock 2>/dev/null || true

# admin/ is not a volume either, so the image puts it back every start.
if [ "$CC_ADMIN_FOLDER" != "admin" ] && [ -d /var/www/html/admin ]; then
    log "admin folder -> $CC_ADMIN_FOLDER"
    rm -rf "/var/www/html/$CC_ADMIN_FOLDER"
    mv /var/www/html/admin "/var/www/html/$CC_ADMIN_FOLDER"
fi

# Friendly URLs are Apache rewrites and the rules live in a .htaccess that
# PrestaShop generates. Without it Apache 404s every link before PHP is reached.
log "regenerating .htaccess"
su -s /bin/sh -c "php /provisioning/deploy/htaccess.php" www-data || \
    log "!! .htaccess generation failed - friendly URLs will 404"

# Tables alone do not mean the shop is ready for migrations. bootstrap.sh is
# what creates the attribute groups and taxonomy they operate on, and it
# baselines the ledger when it finishes. So an existing ledger - not existing
# tables - is the signal that migrations are safe to apply.
#
# Without this, a half-built shop runs migrations against structures that were
# never created, and they fail exactly as they should.
LEDGER=$(mysql -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWD" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='$DB_NAME' AND table_name LIKE '%cc_migration'" 2>/dev/null || echo 0)

if [ "${LEDGER:-0}" = "0" ]; then
    log "!! ============================================================"
    log "!! NO MIGRATION LEDGER. This shop has tables but bootstrap never"
    log "!! finished, so nothing records which migrations have been applied"
    log "!! and every future deploy will SKIP THEM SILENTLY."
    log "!!"
    log "!! Finish the build, which baselines the ledger:"
    log "!!   docker exec <shop> bash /provisioning/deploy/bootstrap.sh --from 2"
    log "!! ============================================================"
    exec docker-php-entrypoint /tmp/docker_run.sh
fi

# 3. Replicas start at the same time and would migrate concurrently. A named
#    lock in the database serialises them: the first replica migrates, the rest
#    wait and then find nothing pending. GET_LOCK is released automatically if
#    the connection dies, so a killed container cannot wedge the next deploy.
log "applying pending migrations"
mysql -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWD" -N -B \
    -e "SELECT GET_LOCK('cc_migrate', 300)" >/dev/null 2>&1 || true

if su -s /bin/sh -c "php /provisioning/deploy/migrate.php" www-data; then
    log "migrations up to date"
else
    # Loud, and still starts. A migration failure that takes the whole service
    # down turns a bad migration into an outage; the previous image is still
    # serving until this replica is healthy, and the logs say what happened.
    log "MIGRATIONS FAILED - starting anyway, roll back to the previous tag"
fi

mysql -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWD" -N -B \
    -e "SELECT RELEASE_LOCK('cc_migrate')" >/dev/null 2>&1 || true


exec docker-php-entrypoint /tmp/docker_run.sh
