.PHONY: install lint format test serve migrate clean build assets deploy

# Default target
all: install build

# Install all dependencies
install:
	cd apps/server && composer install
	cd apps/server && php bin/console importmap:install

# Run linting
lint:
	cd apps/server && vendor/bin/php-cs-fixer fix --dry-run --diff
	cd apps/server && vendor/bin/phpstan analyse --memory-limit=256M

# Format code
format:
	cd apps/server && vendor/bin/php-cs-fixer fix

# Run tests
test:
	cd apps/server && vendor/bin/phpunit

# Start development server
serve:
	cd apps/server && symfony serve

# Alternative serve without Symfony CLI
serve-php:
	cd apps/server && php -S localhost:8000 -t public/

# Run database migrations
migrate:
	cd apps/server && php bin/console doctrine:migrations:migrate --no-interaction

# Create a new migration
migration:
	cd apps/server && php bin/console make:migration

# Compile assets (AssetMapper)
assets:
	cd apps/server && php bin/console asset-map:compile

# Clean build artifacts
clean:
	rm -rf apps/server/var/cache/*
	rm -rf apps/server/node_modules/.cache

# Build everything for production
build: assets
	cd apps/server && composer dump-env prod
	cd apps/server && php bin/console cache:clear

# Create database
db-create:
	cd apps/server && php bin/console doctrine:database:create

# Reset database (drop, create, migrate)
db-reset:
	cd apps/server && php bin/console doctrine:database:drop --force || true
	cd apps/server && php bin/console doctrine:database:create
	cd apps/server && php bin/console doctrine:migrations:migrate --no-interaction

# iOS SDK targets
sdk-build:
	cd packages/sdk-swift && swift build

sdk-test:
	cd packages/sdk-swift && swift test

# Deploy to production (requires .env.deploy)
deploy:
	@test -f .env.deploy || (echo "Error: .env.deploy not found. Copy .env.deploy.example and configure." && exit 1)
	$(eval include .env.deploy)
	$(eval export)
	rsync -avz --delete \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='node_modules' \
		--exclude='vendor' \
		--exclude='.env.local' \
		--exclude='.env.deploy' \
		--exclude='var/cache' \
		--exclude='var/log' \
		--exclude='var/data' \
		--exclude='storage/parquet' \
		-e "ssh -i $(SSH_KEY_PATH)" \
		apps/server/ \
		$(SSH_USER)@$(SSH_HOST):$(DEPLOY_PATH)
	ssh -i $(SSH_KEY_PATH) $(SSH_USER)@$(SSH_HOST) "cd $(DEPLOY_PATH) && \
		mkdir -p var/data var/cache var/log storage/parquet && \
		touch var/data/errata.db && \
		composer install --no-dev --optimize-autoloader && \
		php bin/console importmap:install && \
		php bin/console asset-map:compile && \
		php bin/console cache:clear && \
		php bin/console doctrine:migrations:migrate --no-interaction"
