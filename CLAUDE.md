# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Errata is an iOS issue monitoring platform (crash reporting, error tracking, performance monitoring). This monorepo contains a Symfony backend and Swift iOS SDK.

## Key Commands

```bash
make install    # Install PHP dependencies (composer install)
make lint       # Run php-cs-fixer --dry-run + PHPStan
make format     # Run php-cs-fixer to fix code style
make test       # Run all PHPUnit tests
make serve      # Start Symfony dev server (requires symfony CLI)
make migrate    # Run database migrations
make db-reset   # Drop, create, and migrate database

# Run a single test or test file
cd apps/server && vendor/bin/phpunit tests/Integration/Api/EventControllerTest.php
cd apps/server && vendor/bin/phpunit --filter testMethodName

# iOS SDK
make sdk-build  # Build Swift package
make sdk-test   # Run Swift tests
```

## Architecture

### Data Flow

1. iOS SDK sends events to `POST /api/v1/events` with `X-Errata-Key` header
2. `EventController` validates and dispatches `ProcessEvent` message to Symfony Messenger
3. `ProcessEventHandler` generates fingerprint, finds/creates `Issue`, writes to Parquet storage

### Dual Storage Strategy

- **SQLite** (`var/data/errata.db`): Metadata - projects, users, API keys, issues (aggregated)
- **Parquet** (`storage/parquet/`): Raw event data as wide events for analytics

### Key Backend Components

```
apps/server/src/
├── Controller/
│   ├── Api/EventController.php      # Event ingestion API
│   └── Admin/                        # EasyAdmin controllers
├── Entity/                           # Doctrine entities: Project, Issue, ApiKey, User
├── Message/ProcessEvent.php          # Messenger message for async processing
├── MessageHandler/ProcessEventHandler.php  # Event processing, fingerprinting, storage
├── Service/
│   ├── FingerprintService.php        # Issue grouping via fingerprint generation
│   └── Parquet/                      # Parquet read/write services
├── Security/ApiKeyAuthenticator.php  # API key authentication
└── DTO/WideEventPayload.php          # Event payload structure
```

### iOS SDK Structure

```
packages/sdk-swift/Sources/ErrataSDK/
├── Core/           # Errata.swift (main entry), Configuration
├── Models/         # WideEvent, Span, DeviceInfo
├── Transport/      # EventQueue, EventStore, BatchSender
└── Capture/        # CrashReporter (PLCrashReporter integration)
```

### Testing

- Uses `zenstruck/browser` for fluent HTTP assertions
- `AbstractIntegrationTestCase` provides test fixtures: `createTestUser()`, `createTestProject()`, `createTestApiKey()`, `createTestIssue()`
- Test database is reset between tests via SQLite PRAGMA

## Code Style

- **PHP**: PSR-12 via php-cs-fixer (Symfony ruleset)
- **Static Analysis**: PHPStan with Doctrine and Symfony extensions
- **Commits**: Semantic commits required

## Before Committing

```bash
make lint
make format
```
