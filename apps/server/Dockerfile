# syntax=docker/dockerfile:1
# =============================================================================
# Stage 1: Build PHP extensions (cached separately for faster rebuilds)
# =============================================================================
FROM php:8.5.2-cli-alpine AS extensions

RUN apk add --no-cache \
    autoconf g++ make linux-headers \
    postgresql-dev libzip-dev icu-dev \
    zlib-dev protobuf-dev

# Build native extensions (ctype, iconv, mbstring, pdo, opcache are built-in to PHP 8.5)
RUN docker-php-ext-install pdo_pgsql intl zip bcmath

# Build PECL extensions with cache mounts for faster rebuilds
# Note: grpc PECL extension removed - not compatible with PHP 8.5 yet
# OpenTelemetry OTLP exporter will use HTTP/protobuf transport instead
RUN --mount=type=cache,target=/tmp/pear \
    --mount=type=cache,target=/root/.cache \
    pecl install redis opentelemetry protobuf \
    && docker-php-ext-enable redis opentelemetry protobuf

# =============================================================================
# Stage 2a: Builder base (shared setup for dev and prod)
# =============================================================================
FROM php:8.5.2-cli-alpine AS builder-base

RUN apk add --no-cache git unzip curl \
    postgresql-libs libzip icu-libs oniguruma libstdc++ protobuf

# Copy pre-built extensions from extensions stage
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./

# =============================================================================
# Stage 2b: Builder for development (includes dev dependencies)
# =============================================================================
FROM builder-base AS builder-dev

ENV APP_ENV=dev

RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer run-script post-install-cmd --no-interaction || true
RUN php bin/console importmap:install --no-interaction
RUN php bin/console asset-map:compile --no-interaction
RUN php bin/console cache:warmup

# =============================================================================
# Stage 2c: Builder for production (no dev dependencies)
# =============================================================================
FROM builder-base AS builder-prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Install dependencies without scripts first (for layer caching)
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

COPY . .

# Optimize autoloader and compile env defaults
# Runtime env vars from Dokku/Docker will override these compiled defaults
RUN set -eux; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd || true; \
    php bin/console importmap:install --no-interaction; \
    php bin/console asset-map:compile --no-interaction

# =============================================================================
# Stage 3a: Base runtime for development
# =============================================================================
FROM php:8.5.2-fpm-alpine AS base-dev

ENV APP_ENV=dev

RUN apk add --no-cache \
    postgresql-libs libzip icu-libs oniguruma \
    libstdc++ protobuf \
    curl fcgi

# Copy pre-built extensions from extensions stage
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/99-errata.ini

WORKDIR /app
COPY --from=builder-dev /app /app

RUN mkdir -p /app/var/cache /app/var/log /app/storage \
    && chown -R www-data:www-data /app/var /app/storage /app/public

# =============================================================================
# Stage 3b: Base runtime for production
# =============================================================================
FROM php:8.5.2-fpm-alpine AS base-prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

RUN apk add --no-cache \
    postgresql-libs libzip icu-libs oniguruma \
    libstdc++ protobuf \
    curl fcgi

# Copy pre-built extensions from extensions stage
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/99-errata.ini

WORKDIR /app
COPY --from=builder-prod /app /app

RUN mkdir -p /app/var/cache /app/var/log /app/storage \
    && chown -R www-data:www-data /app/var /app/storage /app/public

# =============================================================================
# Stage 4a: Web development (Nginx + PHP-FPM with Supervisor)
# =============================================================================
FROM base-dev AS web-dev

RUN apk add --no-cache nginx supervisor

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/run/php /var/log/supervisor

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:5000/api/v1/health || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]

# =============================================================================
# Stage 4b: Web production (Nginx + PHP-FPM with Supervisor)
# =============================================================================
FROM base-prod AS web-prod

RUN apk add --no-cache nginx supervisor

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/run/php /var/log/supervisor

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:5000/api/v1/health || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]

# =============================================================================
# Stage 5a: Worker development (Messenger consumer)
# =============================================================================
FROM base-dev AS worker-dev

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# No port exposed - worker doesn't serve HTTP
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"]

# =============================================================================
# Stage 5b: Worker production (Messenger consumer)
# =============================================================================
FROM base-prod AS worker-prod

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# No port exposed - worker doesn't serve HTTP
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"]
