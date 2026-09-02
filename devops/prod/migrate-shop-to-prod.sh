#!/usr/bin/env bash
#
# Move the shop that exists on this workstation onto production. Run from the
# repo root, on the machine holding the real catalogue.
#
#   ./devops/prod/migrate-shop-to-prod.sh
#
# This is the first-run path, and it exists because a deploy ships CODE, not a
# SHOP. The image carries modules, theme and ops scripts; the catalogue, the
# 18,839 generated images, prices and translations live in Docker volumes that
# git has never seen. Deploy alone therefore lands on an empty database, and
# PrestaShop offers its installer.
#
# The alternative - click through that installer and re-run the pipeline on
# production - is the wrong answer, and not only because it is slow. The
# pipeline fetches from five third parties and prices move, so it is neither
# hermetic nor deterministic: it would build a DIFFERENT shop, not this one.
#
# Run once. After this, upgrades are APP_IMAGE_TAG plus a redeploy, forever.
set -euo pipefail

HOST="${HOST:-root@89.117.21.78}"
STACK_APP="${STACK_APP:-doublesleeve}"
STACK_DB="${STACK_DB:-doublesleeve-db}"
DOMAIN="${DOMAIN:-doublesleeve.org}"

LOCAL_DB_CT=cryptocards-db
LOCAL_HTML_VOL=cryptocards_ps_html

say()  { printf '\n==> %s\n' "$1"; }
stop() { printf '!!  %s\n' "$1" >&2; exit 1; }

[ -f .env ] || stop "run me from the repo root - no .env here"
set -a; . ./.env; set +a

docker ps --format '{{.Names}}' | grep -qx "$LOCAL_DB_CT" \
    || stop "$LOCAL_DB_CT is not running - the local shop has to be up to dump it"

# Prod credentials are NOT the local ones. Ask, rather than assume, because
# writing the wrong ones into parameters.php produces a shop that starts and
# then cannot see its own database.
read -r -p "prod DB_NAME [doublesleeve]: " P_DB_NAME;  P_DB_NAME="${P_DB_NAME:-doublesleeve}"
read -r -p "prod DB_USER [doublesleeve]: " P_DB_USER;  P_DB_USER="${P_DB_USER:-doublesleeve}"
read -r -s -p "prod DB_PASSWORD: " P_DB_PASS; echo
[ -n "$P_DB_PASS" ] || stop "need the production database password"

remote() { ssh "$HOST" "$@"; }

# Service tasks, not container names: Swarm renames them on every deploy.
say "finding the running containers on prod"
PROD_DB_CT=$(remote "docker ps -q -f name=${STACK_DB}_db" | head -1)
PROD_SHOP_CT=$(remote "docker ps -q -f name=${STACK_APP}_shop" | head -1)
[ -n "$PROD_DB_CT" ]   || stop "no ${STACK_DB}_db container on $HOST - deploy db.yml first"
[ -n "$PROD_SHOP_CT" ] || stop "no ${STACK_APP}_shop container on $HOST - deploy app.yml first"
echo "  db=$PROD_DB_CT shop=$PROD_SHOP_CT"

# ------------------------------------------------------------------ 1. db ----
# Streamed, never staged: 276 MB compressed, and there is no reason for it to
# touch either disk.
say "database (~276 MB) - dumping and loading in one pass"
docker exec "$LOCAL_DB_CT" mariadb-dump \
        --single-transaction --quick --routines --events \
        -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
    | gzip -1 \
    | ssh "$HOST" "gunzip | docker exec -i $PROD_DB_CT \
        mariadb -u'$P_DB_USER' -p'$P_DB_PASS' '$P_DB_NAME'"

# --------------------------------------------------------------- 2. images ---
# 3.6 GB, and the slow part. Mostly JPEG and PNG already, so it is piped
# uncompressed - gzip would burn CPU for a percent or two.
say "images (3.6 GB) - this is the long one"
docker run --rm -v "$LOCAL_HTML_VOL":/h alpine tar -cf - -C /h img \
    | ssh "$HOST" "docker run --rm -i -v ${STACK_APP}_ps_img:/img alpine \
        tar -xf - -C / --strip-components=0 --transform='s|^img/||' -C /img"

# --------------------------------------------------------------- 3. config ---
# parameters.php is what PrestaShop checks to decide whether it is installed at
# all. Its absence is exactly why production offered the installer. Carry the
# local one across for its secret and cookie keys, and repoint the database.
say "app/config/parameters.php, repointed at the prod database"
docker run --rm -v "$LOCAL_HTML_VOL":/h alpine cat /h/app/config/parameters.php \
    | python3 -c "
import re,sys
s=sys.stdin.read()
for k,v in [('database_host','db'),('database_name','$P_DB_NAME'),
            ('database_user','$P_DB_USER'),('database_password','''$P_DB_PASS'''),
            ('database_port','')]:
    s=re.sub(r\"('\"+k+r\"'\s*=>\s*)'[^']*'\", lambda m: m.group(1)+repr(v).replace('\\\"',\"'\"), s, count=1)
sys.stdout.write(s)
" \
    | ssh "$HOST" "docker exec -i $PROD_SHOP_CT \
        sh -c 'cat > /var/www/html/app/config/parameters.php \
               && chown www-data:www-data /var/www/html/app/config/parameters.php'"

# ------------------------------------------------------------------ 4. urls ---
# Read the prefix out of parameters.php rather than assuming ps_. PrestaShop
# randomises it per install; this shop is cc_, and hardcoding the wrong one
# means these statements match zero rows and report success.
# The database still says localhost. Until this changes, every generated link,
# asset and redirect points at a machine the customer cannot reach.
PREFIX=$(docker run --rm -v "$LOCAL_HTML_VOL":/h alpine \
    grep -o "'database_prefix' => '[^']*'" /h/app/config/parameters.php \
    | sed "s/.*=> '//;s/'//")
[ -n "$PREFIX" ] || stop "could not read database_prefix from parameters.php"

say "pointing the shop at $DOMAIN (prefix ${PREFIX})"
remote "docker exec -i $PROD_DB_CT mariadb -u'$P_DB_USER' -p'$P_DB_PASS' '$P_DB_NAME'" <<SQL
UPDATE ${PREFIX}shop_url SET domain='$DOMAIN', domain_ssl='$DOMAIN', physical_uri='/';
UPDATE ${PREFIX}configuration SET value='$DOMAIN'
    WHERE name IN ('PS_SHOP_DOMAIN','PS_SHOP_DOMAIN_SSL');
UPDATE ${PREFIX}configuration SET value='1'
    WHERE name IN ('PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE');
SQL

# ----------------------------------------------------------------- 5. cache ---
# The cache was compiled against localhost and the old container paths.
say "clearing the cache"
remote "docker exec -u www-data $PROD_SHOP_CT sh -c 'rm -rf /var/www/html/var/cache/*'"

say "done"
cat <<EOF

  https://$DOMAIN/
  https://$DOMAIN/${PS_FOLDER_ADMIN:-<PS_FOLDER_ADMIN>}/

The installer is gone: the entrypoint removes /install/ on any shop that already
has tables, on every start, because the base image puts it back each deploy.

Search will return nothing until the index is rebuilt on prod - it is derived,
so it did not travel:

  docker exec -u www-data $PROD_SHOP_CT php /provisioning/catalog/search-index.php
EOF
