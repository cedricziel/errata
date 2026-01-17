# Errata Project

## Overview
Errata is an iOS issue monitoring platform. This is a monorepo containing a Symfony backend and a Swift iOS SDK.

## Technology Stack

### Backend
- **Framework**: Symfony 7.2
- **PHP**: 8.2+
- **Database**: SQLite
- **Event Storage**: Parquet (flow-php/parquet)

### iOS SDK
- **Language**: Swift Package
- **Crash Reporting**: PLCrashReporter
- **Logging**: swift-log

### Frontend
- **Templates**: Twig
- **Styling**: Tailwind CSS
- **JavaScript**: Stimulus, Turbo
- **Assets**: Symfony AssetMapper (no npm)

## Key Commands

```bash
make install    # Install dependencies
make lint       # Run PHPCS + PHPStan
make format     # Run PHP CS Fixer
make test       # Run PHPUnit tests
make serve      # Start Symfony dev server
make migrate    # Run database migrations
```

## Directory Structure

- `apps/server/` - Symfony backend
- `packages/sdk-swift/` - iOS SDK
- `docs/` - Documentation

## Code Style

- **PHP**: PSR-12 (phpcs) + Symfony style (php-cs-fixer)
- **Swift**: Standard Swift conventions
- **Commits**: Semantic commits required

## Development Notes

- API authentication via `X-Errata-Key` header
- Events stored in Parquet files under `apps/server/storage/parquet/`
- SQLite database at `apps/server/var/data/errata.db`

## Before Committing

Always run:
```bash
make lint
make format
```

## CI/CD

### GitHub Actions Workflows

- **CI** (`.github/workflows/ci.yml`) - Runs on PRs and pushes to main:
  - PHP lint (PHPCS + PHPStan)
  - PHP tests (PHPUnit)
  - Swift build and tests
  - Frontend build

- **Deploy** (`.github/workflows/deploy.yml`) - Deploys to production on push to main

### Dependabot

Configured in `.github/dependabot.yml` for:
- Composer (PHP) dependencies
- Swift Package dependencies
- GitHub Actions
- npm dependencies

### Required Secrets (Environment: production)

Configure these in GitHub repository settings under Environments → production:

| Secret | Description |
|--------|-------------|
| `SSH_PRIVATE_KEY` | Private SSH key for deployment |
| `SSH_HOST` | Production server hostname |
| `SSH_USER` | SSH username for deployment |
| `DEPLOY_PATH` | Absolute path on server (e.g., `/var/www/errata`)
