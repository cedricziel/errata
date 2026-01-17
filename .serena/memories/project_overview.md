# Errata Project Overview

## Purpose
iOS issue monitoring platform (crash reporting, error tracking, performance monitoring).

## Tech Stack
- **Backend**: Symfony 7.x (PHP 8.2+)
- **Database**: SQLite for metadata, Parquet for raw events
- **Frontend**: Twig templates, TailwindCSS, Stimulus.js
- **iOS SDK**: Swift with PLCrashReporter

## Code Style
- PSR-12 via php-cs-fixer (Symfony ruleset)
- Static analysis with PHPStan
- Semantic commits required

## Key Patterns
- EasyAdmin for admin CRUD controllers
- Twig GlobalsInterface for global template vars
- Stimulus controllers in assets/controllers/
- Repository pattern for database access
