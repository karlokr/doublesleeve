#!/usr/bin/env bash
#
# Deploy a version to production.
#
#   ./deploy.sh v1.4.0      deploy that tag
#   ./deploy.sh             deploy whatever APP_IMAGE_TAG says
#
# The first run installs the shop. Every run after it is an upgrade, and the
# difference is decided by looking at the database rather than by remembering
# which kind of run this is - see "first run" below.
#
# What makes this safe is not this script. It is the rule the migrations follow:
# they are FORWARD-ONLY and BACKWARD-COMPATIBLE. Deploying an older image is
# always allowed because the newer schema still works under the older code. See
# docs/deployment.md, "The upgrade contract".
set -euo pipefail

cd "$(dirname "$0")/../.."
COMPOSE="docker compose --project-name doublesleeve --project-directory . \
  --env-file devops/prod/.env -f devops/prod/compose.yml"

if [ $# -ge 1 ]; then
  export APP_IMAGE_TAG="$1"
fi
TAG="${APP_IMAGE_TAG:-latest}"
echo "==> deploying ${TAG}"

# The image is fetched before anything is touched, so a bad tag or an
# unreachable registry fails while the running shop is still the old one.
echo "==> pulling"
APP_IMAGE_TAG="$TAG" $COMPOSE pull shop cron

PREVIOUS="$(docker inspect --format '{{index .Config.Image}}' doublesleeve-shop 2>/dev/null || echo none)"
echo "==> currently running: ${PREVIOUS}"

# The database is the thing that cannot be rolled back by redeploying, so it is
# the thing that gets a copy first. Images and code roll back for free.
echo "==> backing up the database"
mkdir -p backups
$COMPOSE exec -T db sh -c 'exec mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  | gzip > "backups/pre-${TAG}-$(date +%Y%m%d-%H%M%S).sql.gz"
ls -1t backups | head -1

echo "==> starting database and search"
APP_IMAGE_TAG="$TAG" $COMPOSE up -d db meilisearch

# First run: no shop tables, so this is an install rather than an upgrade. The
# check is on the DATABASE, not on a marker file, because the database is the
# only thing that actually knows.
INSTALLED="$($COMPOSE exec -T db sh -c \
  'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -N -B -e \
   "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$MARIADB_DATABASE\" AND table_name LIKE \"%_shop\""' \
  2>/dev/null || echo 0)"

if [ "${INSTALLED:-0}" = "0" ]; then
  echo "==> FIRST RUN: installing PrestaShop, then provisioning"
  APP_IMAGE_TAG="$TAG" PS_INSTALL_AUTO=1 $COMPOSE up -d shop
  echo "    waiting for the installer"
  for _ in $(seq 1 60); do
    sleep 5
    if $COMPOSE exec -T shop test -f /var/www/html/app/config/parameters.php 2>/dev/null; then break; fi
  done
  $COMPOSE exec -T -u www-data shop php /provisioning/setup/setup.php
  $COMPOSE exec -T -u www-data shop php /provisioning/setup/facets.php
  $COMPOSE exec -T -u www-data shop php /provisioning/setup/storefront.php
  $COMPOSE exec -T -u www-data shop php /provisioning/setup/pages.php
  # Everything shipped so far is already in this image; record it rather than
  # replay it against a shop that setup.php just built correctly.
  $COMPOSE exec -T -u www-data shop php /provisioning/deploy/migrate.php --baseline
else
  echo "==> UPGRADE: applying pending migrations only"
  # Run inside the NEW image, because the migrations that need running are the
  # ones it brought with it. A one-shot container, so a failure here never
  # leaves a half-started web container serving traffic.
  APP_IMAGE_TAG="$TAG" $COMPOSE run --rm --no-deps -u www-data shop \
    php /provisioning/deploy/migrate.php
  APP_IMAGE_TAG="$TAG" $COMPOSE up -d shop cron
fi

echo "==> clearing cache"
$COMPOSE exec -T -u www-data shop rm -rf /var/www/html/var/cache/prod

echo "==> health check"
for i in $(seq 1 20); do
  sleep 3
  CODE="$($COMPOSE exec -T shop sh -c 'curl -s -o /dev/null -w "%{http_code}" http://localhost/' || echo 000)"
  if [ "$CODE" = "200" ]; then
    echo "==> ${TAG} is live"
    exit 0
  fi
done

echo "!! health check never returned 200 (last: ${CODE:-none})"
echo "!! roll back with:  ./devops/prod/deploy.sh ${PREVIOUS##*:}"
echo "!! the schema is forward-compatible, so the previous image runs against it"
exit 1
