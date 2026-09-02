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
FROM prestashop/prestashop:9.1.4-8.3

# Our modules live where PrestaShop expects modules to live. In development the
# installers copy them in from a bind mount; here they simply are where they
# belong, and the installers have nothing left to copy.
COPY src/modules/ /var/www/html/modules/

# The operator's scripts, at the path every one of them already assumes. Nothing
# inside ops/ refers to a host path, which is why the layout above it could move
# without touching a single script.
COPY src/ops/ /provisioning/

COPY devops/image/php/custom.ini /usr/local/etc/php/conf.d/zz-cryptocards.ini

# The module tree arrives owned by root from the build context; PrestaShop writes
# to it (cache, config) as www-data.
RUN chown -R www-data:www-data /var/www/html/modules
