# The shop, as one artifact.
#
# Without this there is nothing anywhere that is "PrestaShop AND our shop" - the
# base image runs and our work sits beside it as bind mounts, which is right for
# development and leaves nothing to tag, pull or roll back. Baking it in is what
# makes a deploy atomic, a rollback real, and a PrestaShop upgrade a one-line
# change to the FROM below.
#
# Built from the repository root, because it copies src/:
#
#   docker build -f devops/image/Dockerfile -t ghcr.io/karlokr/doublesleeve:$(git rev-parse --short HEAD) .
#
# IMPORTANT: production must not mount a volume over /var/www/html. A volume
# over that path hides everything copied in below - see devops/prod/compose.yml,
# which mounts only the directories that genuinely hold state.
# The PrestaShop version lives HERE and nowhere else. It used to also be a
# PS_VERSION entry in .env, which meant an environment file could disagree with
# the image about which PrestaShop this is - and once the image is the artifact,
# the image is the only thing that can be right. Upgrading is this line, plus
# the same line in cron/Dockerfile.
#
# Tag format is <ps-version>-<php-version>. PS 9 supports PHP 8.2-8.5; 8.3 is
# the safest bet for third-party modules.
FROM prestashop/prestashop:9.1.4-8.3

# Our modules live where PrestaShop expects modules to live. In development the
# installers copy them in from a bind mount; here they simply are where they
# belong, and the installers have nothing left to copy.
# Without this the pushed package is an orphan: GHCR shows it under the account
# but the repository page says "No packages published", which reads exactly like
# a release that published notes without ever pushing an image. The label is
# what associates the two.
LABEL org.opencontainers.image.source="https://github.com/karlokr/doublesleeve" \
      org.opencontainers.image.description="DoubleSleeve: Pokemon TCG store on PrestaShop 9" \
      org.opencontainers.image.licenses="UNLICENSED"

COPY --chown=www-data:www-data src/modules/ /var/www/html/modules/

# The operator's scripts, at the path every one of them already assumes. Nothing
# inside ops/ refers to a host path, which is why the layout above it could move
# without touching a single script.
COPY src/ops/ /provisioning/

COPY devops/image/php/custom.ini /usr/local/etc/php/conf.d/zz-cryptocards.ini

# Ownership is set by COPY --chown above rather than by a RUN chown afterwards.
# A chown rewrites every file it touches into a new layer, so the modules were
# being stored twice: once owned by root, once by www-data.
