# Suggested Commands

## Development
- `make install` - Install PHP dependencies
- `make serve` - Start Symfony dev server
- `make migrate` - Run database migrations
- `make db-reset` - Drop, create, and migrate database

## Testing
- `make test` - Run all PHPUnit tests
- `cd apps/server && vendor/bin/phpunit tests/Integration/...` - Run specific tests
- `cd apps/server && vendor/bin/phpunit --filter testMethodName` - Run test by name

## Code Quality
- `make lint` - Run php-cs-fixer --dry-run + PHPStan
- `make format` - Run php-cs-fixer to fix code style

## iOS SDK
- `make sdk-build` - Build Swift package
- `make sdk-test` - Run Swift tests
