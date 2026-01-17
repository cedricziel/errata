.PHONY: install lint format test serve migrate clean build assets

# Default target
all: install build

# Install all dependencies
install:
	cd apps/server && composer install
	cd apps/server && npm install

# Run linting
lint:
	cd apps/server && vendor/bin/phpcs src/ --standard=PSR12 || true
	cd apps/server && vendor/bin/phpstan analyse src/ -l 5 || true

# Format code
format:
	cd apps/server && vendor/bin/php-cs-fixer fix src/ --rules=@Symfony

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

# Build frontend assets
assets:
	cd apps/server && npm run build

# Watch frontend assets
watch:
	cd apps/server && npm run watch

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
