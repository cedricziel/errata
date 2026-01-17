# Errata

iOS Issue Monitoring Platform - An open-source crash reporting, error tracking, and performance monitoring solution for iOS applications.

## Overview

Errata provides:
- **Crash Reporting**: Automatic crash capture with symbolication support
- **Error Tracking**: Capture and group application errors
- **Performance Monitoring**: Track app performance with spans and metrics
- **Issue Grouping**: Intelligent fingerprinting to group related events

## Architecture

- **Backend**: Symfony 7.2 (PHP)
- **Metadata Storage**: SQLite (projects, users, API keys, issues)
- **Event Storage**: Parquet (wide events for analytics)
- **Dashboard**: Twig + Turbo + Stimulus
- **iOS SDK**: Swift Package

## Project Structure

```
errata/
├── apps/
│   └── server/          # Symfony backend
├── packages/
│   └── sdk-swift/       # iOS SDK
└── docs/
    ├── api.md           # API documentation
    └── sdk-integration.md
```

## Quick Start

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- Symfony CLI (optional, for development server)

### Installation

```bash
# Install dependencies
make install

# Run database migrations
make migrate

# Start development server
make serve
```

### Configuration

1. Copy `.env` to `.env.local` in `apps/server/`
2. Configure your `DATABASE_URL` if needed
3. Generate a secure `APP_SECRET` for production

## iOS SDK Integration

Add to your Swift Package dependencies:

```swift
.package(url: "https://github.com/yourorg/errata", from: "0.1.0")
```

Initialize in your app:

```swift
import ErrataSDK

Errata.shared.start(with: Configuration(dsn: "https://<key>@<host>/<project>"))
```

## API Documentation

See [docs/api.md](docs/api.md) for complete API documentation.

## License

MIT License
