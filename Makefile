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
	docker exec $(SHOP) bash /provisioning/setup/rename-admin.sh $(PS_FOLDER_ADMIN)
	docker exec -u www-data $(SHOP) php /provisioning/setup/setup.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/storefront.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/pages.php
	docker exec $(SHOP) bash /provisioning/installers/install-search-module.sh
	docker exec $(SHOP) bash /provisioning/installers/install-theme-module.sh
	docker exec $(SHOP) bash /provisioning/installers/install-copies-module.sh
	docker exec $(SHOP) bash /provisioning/installers/install-i18n-module.sh
	docker exec -u www-data $(SHOP) php /provisioning/migrations/card-language.php
	docker exec -u www-data $(SHOP) php /provisioning/migrations/grader-axis.php
	docker exec -u www-data $(SHOP) php /provisioning/migrations/retire-print-region.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/translations.php
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

search-index: ## Rebuild the Meilisearch index from the catalogue
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

purge-demo: ## Delete PrestaShop's demo catalogue (run once, before real inventory)
	@read -p "Deletes ALL products plus demo categories/attributes. Type 'yes': " c; [ "$$c" = yes ]
	docker exec -u www-data $(SHOP) php /provisioning/setup/purge-demo.php

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
	docker exec -u www-data $(SHOP) php /provisioning/inventory/seed-inventory.php
	docker exec -u www-data $(SHOP) php /provisioning/media/seed-category-images.php
	docker exec -u www-data $(SHOP) php /provisioning/media/cutout-images.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

currency-sync: ## Refresh storefront currency rates from the Bank of Canada
	docker exec -u www-data $(SHOP) php /provisioning/pricing/currency-sync.php

price-setup: ## Create price engine tables and load the source map
	docker exec -u www-data $(SHOP) php /provisioning/setup/price-schema.php

price-sync: ## Dry run: compute prices, journal proposals, change nothing
	docker exec -u www-data $(SHOP) php /provisioning/pricing/price-sync.php

price-apply: ## Actually reprice (still gated by confidence + guard rails)
	docker exec -u www-data $(SHOP) php /provisioning/pricing/price-sync.php --apply

price-report: ## Summarise the most recent price run
	docker exec -u www-data $(SHOP) php /provisioning/pricing/price-report.php

price-freeze: ## Freeze a product's price (usage: make price-freeze ID=20)
	docker exec -u www-data $(SHOP) php -r 'require "/var/www/html/config/config.inc.php"; \
	  Db::getInstance()->execute("INSERT INTO "._DB_PREFIX_."price_policy (id_product, frozen) VALUES ($(ID),1) ON DUPLICATE KEY UPDATE frozen=1"); \
	  echo "product $(ID) frozen\n";'

align: ## Align catalogue vocabularies with TCGplayer (printings, rarities, card data)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/align-tcgplayer.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php

sets-align: ## Rebuild the Western set taxonomy from TCGplayer groups (products untouched)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/sets-tcgplayer.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/pages.php

sets-align-jp: ## Rebuild the Japanese set taxonomy (products untouched)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/sets-tcgplayer.php Japanese
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php

catalog-repair: ## Re-home every product onto its set and re-derive Set/Region (bulk repair)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/sets-tcgplayer.php Western --rehome
	docker exec -u www-data $(SHOP) php /provisioning/catalog/sets-tcgplayer.php Japanese --rehome

add-card: ## Add one card to stock: make add-card GROUP=2545 NUMBER=SWSH075 [QTY=1] [CONDITION="Near Mint"]
	docker exec -u www-data $(SHOP) php /provisioning/inventory/add-card.php \
		--group=$(GROUP) --number=$(NUMBER) $(if $(QTY),--qty=$(QTY)) \
		$(if $(CONDITION),--condition="$(CONDITION)") $(if $(PRINTING),--printing="$(PRINTING)")

seed-japanese: ## Seed the Japanese fixture stock (singles + sealed, idempotent)
	docker exec -u www-data $(SHOP) php /provisioning/inventory/seed-japanese.php

seed-graded: ## Seed graded slab combinations with composited photos (idempotent)
	docker exec -u www-data $(SHOP) php /provisioning/inventory/seed-graded.php

slab-photos: ## Photograph every graded copy inside its own slab (self-healing)
	docker exec -u www-data $(SHOP) php /provisioning/media/slab-photos.php

slab-frames: ## Regenerate assets/slabs/ (one frame per grader per grade)
	docker exec $(SHOP) rm -rf /tmp/slabs
	docker exec -u www-data $(SHOP) php /provisioning/media/make-slab-frames.php
	rm -rf ops/assets/slabs && mkdir -p ops/assets/slabs
	docker cp $(SHOP):/tmp/slabs/. ops/assets/slabs/
	@echo "   run 'make slab-photos' to re-shoot listings with the new frames"

base-set-unlimited: ## Rename shadowed Base Set printings to Unlimited (idempotent)
	docker exec -u www-data $(SHOP) php /provisioning/migrations/base-set-unlimited.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php

sku-rebuild: ## Rebuild combinations as TCGplayer SKUs (printing x condition)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/sku-rebuild.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

seed-shadowless: ## Seed Base Set (Shadowless) stock alongside the shadowed printing
	docker exec -u www-data $(SHOP) php /provisioning/inventory/seed-shadowless.php
	docker exec -u www-data $(SHOP) php /provisioning/media/cutout-images.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

translations: ## Fill the fr-CA gaps the translation packs leave behind
	docker exec -u www-data $(SHOP) php /provisioning/setup/translations.php

card-language: ## Move card language onto the product (retires the old SKU axis)
	docker exec -u www-data $(SHOP) php /provisioning/migrations/card-language.php
	docker exec -u www-data $(SHOP) php /provisioning/migrations/grader-axis.php
	docker exec -u www-data $(SHOP) php /provisioning/migrations/retire-print-region.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/facets.php

derive-names: ## Re-derive every listing title in every storefront language
	docker exec -u www-data $(SHOP) php /provisioning/catalog/derive-names.php $(if $(OFFLINE),--offline)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/search-index.php

nav-images: ## Build nav + homepage tile artwork (assets/graded-frame.webp holds the slab)
	docker exec -u www-data $(SHOP) php /provisioning/media/seed-nav-images.php
	docker exec -u www-data $(SHOP) php /provisioning/setup/storefront.php

card-backs: ## Attach the correct card back as a second photo on every card (idempotent)
	docker exec -u www-data $(SHOP) php /provisioning/media/backfill-card-backs.php

cutout-images: ## Give every fetched image a transparent background
	docker exec -u www-data $(SHOP) php /provisioning/media/cutout-images.php $(if $(FORCE),--force)

copies-init: ## Create serialised card copies from current stock (singles, graded, sealed)
	docker exec -u www-data $(SHOP) php /provisioning/setup/copies-schema.php

copies-release: ## Release expired copy reservations and verify the stock invariant
	docker exec -u www-data $(SHOP) php /provisioning/inventory/copies-release.php

audit: ## Audit printings and parallel print runs against live TCGplayer data
	docker exec -u www-data $(SHOP) php /provisioning/audits/audit-printings.php
	docker exec -u www-data $(SHOP) php /provisioning/audits/audit-parallel-sets.php
	docker exec -u www-data $(SHOP) php /provisioning/audits/audit-editions.php

pokepedia: ## Refresh the French set catalogue from Pokepedia (slow: ~500 pages)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/fetch-pokepedia.php
	docker cp $(SHOP):/tmp/pokepedia-sets.csv provisioning/data/pokepedia-sets.csv

bulbapedia: ## Refresh official French set names from Bulbapedia
	docker exec -u www-data $(SHOP) php /provisioning/catalog/fetch-set-names.php
	docker cp $(SHOP):/tmp/set-names-bulbapedia.csv provisioning/data/set-names-bulbapedia.csv

set-names: ## Resolve a French name for every set (Pokepedia, Bulbapedia, then rules)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/resolve-set-names.php
	docker cp $(SHOP):/tmp/set-names-fr.csv provisioning/data/set-names-fr.csv

species-names: ## Refresh French Pokemon species names from PokeAPI
	docker exec -u www-data $(SHOP) php /provisioning/catalog/fetch-species-names.php
	docker cp $(SHOP):/tmp/pokemon-species.csv provisioning/data/pokemon-species.csv

set-logos: ## Backfill set logos missing from pokemontcg.io (writes data/set-logos-extra.csv)
	docker exec -u www-data $(SHOP) php /provisioning/catalog/fetch-set-logos.php
	docker cp $(SHOP):/tmp/set-logos-extra.csv provisioning/data/set-logos-extra.csv
	docker exec -u www-data $(SHOP) php /provisioning/media/seed-category-images.php --force

seed-photos: ## Add stock back images + placeholder per-copy photo sets
	docker exec -u www-data $(SHOP) php /provisioning/inventory/seed-photos.php
