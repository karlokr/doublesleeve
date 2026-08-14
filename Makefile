SHELL := /bin/bash
COMPOSE := docker compose
SHOP := cryptocards-shop
DB := cryptocards-db

include .env
export

.DEFAULT_GOAL := help

.PHONY: help up down restart logs shell dbshell provision reset backup status

help: ## Show available targets
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start the stack (first run installs PrestaShop, takes a few minutes)
	@test -f .env || (cp .env.example .env && echo "!! .env created from example - set real passwords")
	$(COMPOSE) up -d
	@echo "Shop:       http://$(PS_DOMAIN)/"
	@echo "Admin:      http://$(PS_DOMAIN)/$(PS_FOLDER_ADMIN)/"
	@echo "phpMyAdmin: http://localhost:$(PMA_PORT)/"
	@echo "Mailpit:    http://localhost:$(MAILPIT_UI_PORT)/"

down: ## Stop the stack (data is kept in volumes)
	$(COMPOSE) down

restart: ## Restart the PrestaShop container
	$(COMPOSE) restart prestashop

status: ## Show container status
	$(COMPOSE) ps

logs: ## Tail PrestaShop logs
	$(COMPOSE) logs -f prestashop

shell: ## Shell into the PrestaShop container
	docker exec -it $(SHOP) bash

dbshell: ## MySQL shell on the shop database
	docker exec -it $(DB) mariadb -u$(DB_USER) -p$(DB_PASSWORD) $(DB_NAME)

provision: ## Apply CryptoCards config + Pokemon catalog model (idempotent)
	docker exec $(SHOP) bash /provisioning/rename-admin.sh $(PS_FOLDER_ADMIN)
	docker exec -u www-data $(SHOP) php /provisioning/setup.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/storefront.php
	docker exec -u www-data $(SHOP) php /provisioning/pages.php
	docker exec $(SHOP) bash /provisioning/install-search-module.sh
	docker exec $(SHOP) bash /provisioning/install-theme-module.sh
	docker exec $(SHOP) bash /provisioning/install-copies-module.sh
	docker exec $(SHOP) bash /provisioning/install-i18n-module.sh
	docker exec -u www-data $(SHOP) php /provisioning/card-language.php
	docker exec -u www-data $(SHOP) php /provisioning/translations.php
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

search-index: ## Rebuild the Meilisearch index from the catalogue
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

purge-demo: ## Delete PrestaShop's demo catalogue (run once, before real inventory)
	@read -p "Deletes ALL products plus demo categories/attributes. Type 'yes': " c; [ "$$c" = yes ]
	docker exec -u www-data $(SHOP) php /provisioning/purge-demo.php

backup: ## Dump the database to backups/
	@mkdir -p backups
	docker exec $(DB) mariadb-dump -u$(DB_USER) -p$(DB_PASSWORD) $(DB_NAME) \
		| gzip > backups/cryptocards-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -lh backups | tail -1

reset: ## DESTROY all shop data and volumes, then reinstall from scratch
	@read -p "This deletes the database and all shop files. Type 'yes': " c; [ "$$c" = yes ]
	$(COMPOSE) down -v
	$(COMPOSE) up -d

seed: ## Seed real card + sealed inventory with images (idempotent)
	docker exec -u www-data $(SHOP) php /provisioning/seed-inventory.php
	docker exec -u www-data $(SHOP) php /provisioning/seed-category-images.php
	docker exec -u www-data $(SHOP) php /provisioning/cutout-images.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

currency-sync: ## Refresh storefront currency rates from the Bank of Canada
	docker exec -u www-data $(SHOP) php /provisioning/currency-sync.php

price-setup: ## Create price engine tables and load the source map
	docker exec -u www-data $(SHOP) php /provisioning/price-schema.php

price-sync: ## Dry run: compute prices, journal proposals, change nothing
	docker exec -u www-data $(SHOP) php /provisioning/price-sync.php

price-apply: ## Actually reprice (still gated by confidence + guard rails)
	docker exec -u www-data $(SHOP) php /provisioning/price-sync.php --apply

price-report: ## Summarise the most recent price run
	docker exec -u www-data $(SHOP) php /provisioning/price-report.php

price-freeze: ## Freeze a product's price (usage: make price-freeze ID=20)
	docker exec -u www-data $(SHOP) php -r 'require "/var/www/html/config/config.inc.php"; \
	  Db::getInstance()->execute("INSERT INTO "._DB_PREFIX_."price_policy (id_product, frozen) VALUES ($(ID),1) ON DUPLICATE KEY UPDATE frozen=1"); \
	  echo "product $(ID) frozen\n";'

align: ## Align catalogue vocabularies with TCGplayer (printings, rarities, card data)
	docker exec -u www-data $(SHOP) php /provisioning/align-tcgplayer.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php

sets-align: ## Rebuild set taxonomy from TCGplayer groups
	docker exec -u www-data $(SHOP) php /provisioning/sets-tcgplayer.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/pages.php

sku-rebuild: ## Rebuild combinations as TCGplayer SKUs (printing x condition)
	docker exec -u www-data $(SHOP) php /provisioning/sku-rebuild.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

seed-shadowless: ## Seed Base Set (Shadowless) stock alongside the shadowed printing
	docker exec -u www-data $(SHOP) php /provisioning/seed-shadowless.php
	docker exec -u www-data $(SHOP) php /provisioning/cutout-images.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

translations: ## Fill the fr-CA gaps the translation packs leave behind
	docker exec -u www-data $(SHOP) php /provisioning/translations.php

card-language: ## Move card language onto the product (retires the old SKU axis)
	docker exec -u www-data $(SHOP) php /provisioning/card-language.php
	docker exec -u www-data $(SHOP) php /provisioning/facets.php

derive-names: ## Re-derive every listing title in every storefront language
	docker exec -u www-data $(SHOP) php /provisioning/derive-names.php $(if $(OFFLINE),--offline)
	docker exec -u www-data $(SHOP) php /provisioning/search-index.php

nav-images: ## Build nav + homepage tile artwork (assets/graded-frame.webp holds the slab)
	docker exec -u www-data $(SHOP) php /provisioning/seed-nav-images.php
	docker exec -u www-data $(SHOP) php /provisioning/storefront.php

cutout-images: ## Give every fetched image a transparent background
	docker exec -u www-data $(SHOP) php /provisioning/cutout-images.php $(if $(FORCE),--force)

copies-init: ## Create serialised card copies from current stock (sealed excluded)
	docker exec -u www-data $(SHOP) php /provisioning/copies-schema.php

copies-release: ## Release expired copy reservations and verify the stock invariant
	docker exec -u www-data $(SHOP) php /provisioning/copies-release.php

audit: ## Audit printings and parallel print runs against live TCGplayer data
	docker exec -u www-data $(SHOP) php /provisioning/audit-printings.php
	docker exec -u www-data $(SHOP) php /provisioning/audit-parallel-sets.php
	docker exec -u www-data $(SHOP) php /provisioning/audit-editions.php

pokepedia: ## Refresh the French set catalogue from Pokepedia (slow: ~500 pages)
	docker exec -u www-data $(SHOP) php /provisioning/fetch-pokepedia.php
	docker cp $(SHOP):/tmp/pokepedia-sets.csv provisioning/data/pokepedia-sets.csv

bulbapedia: ## Refresh official French set names from Bulbapedia
	docker exec -u www-data $(SHOP) php /provisioning/fetch-set-names.php
	docker cp $(SHOP):/tmp/set-names-bulbapedia.csv provisioning/data/set-names-bulbapedia.csv

set-names: ## Resolve a French name for every set (Pokepedia, Bulbapedia, then rules)
	docker exec -u www-data $(SHOP) php /provisioning/resolve-set-names.php
	docker cp $(SHOP):/tmp/set-names-fr.csv provisioning/data/set-names-fr.csv

species-names: ## Refresh French Pokemon species names from PokeAPI
	docker exec -u www-data $(SHOP) php /provisioning/fetch-species-names.php
	docker cp $(SHOP):/tmp/pokemon-species.csv provisioning/data/pokemon-species.csv

set-logos: ## Backfill set logos missing from pokemontcg.io (writes data/set-logos-extra.csv)
	docker exec -u www-data $(SHOP) php /provisioning/fetch-set-logos.php
	docker cp $(SHOP):/tmp/set-logos-extra.csv provisioning/data/set-logos-extra.csv
	docker exec -u www-data $(SHOP) php /provisioning/seed-category-images.php --force

seed-photos: ## Add stock back images + placeholder per-copy photo sets
	docker exec -u www-data $(SHOP) php /provisioning/seed-photos.php
