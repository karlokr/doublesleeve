# Scheduler sidecar.
#
# The PrestaShop image ships no cron daemon, so scheduled work gets its own
# container built from the same base. It shares the ps_html volume, so it runs the
# exact code the web container serves - no drift between "the app" and "the jobs".
FROM prestashop/prestashop:9.1.4-8.3

RUN apt-get update \
 && apt-get install -y --no-install-recommends cron \
 && rm -rf /var/lib/apt/lists/*

COPY src/ops/crontab /etc/cron.d/cryptocards
RUN chmod 0644 /etc/cron.d/cryptocards \
 && touch /var/log/cryptocards-price.log \
          /var/log/cryptocards-search.log \
          /var/log/cryptocards-copies.log \
          /var/log/cryptocards-currency.log \
 && chown www-data:www-data /var/log/cryptocards-*.log

# Foreground, and log to stdout so `docker compose logs cron` just works.
CMD ["cron", "-f", "-L", "15"]
