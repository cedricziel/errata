# syntax=docker/dockerfile:1
# =============================================================================
# Stage 1: Build PHP extensions (cached separately for faster rebuilds)
# =============================================================================
FROM php:8.5.2-cli-alpine AS extensions

RUN apk add --no-cache \
    autoconf g++ make linux-headers \
    postgresql-dev libzip-dev icu-dev oniguruma-dev \
    linux-headers zlib-dev grpc-dev protobuf-dev

# Build native extensions (ctype, iconv are built-in to PHP 8.5)
RUN docker-php-ext-install pdo pdo_pgsql intl opcache zip mbstring

# Build PECL extensions with cache mounts for faster rebuilds
RUN --mount=type=cache,target=/tmp/pear \
    --mount=type=cache,target=/root/.cache \
    pecl install redis opentelemetry protobuf grpc \
    && docker-php-ext-enable redis opentelemetry protobuf grpc

# =============================================================================
# Stage 2: Build application dependencies and compile assets
# =============================================================================
FROM php:8.5.2-cli-alpine AS builder

RUN apk add --no-cache git unzip curl \
    postgresql-libs libzip icu-libs oniguruma libstdc++ grpc protobuf

# Copy pre-built extensions from extensions stage
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer run-script post-install-cmd --no-interaction || true
RUN php bin/console importmap:install --no-interaction
RUN php bin/console asset-map:compile --no-interaction
RUN APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# =============================================================================
# Stage 3: Base runtime (shared by web and worker)
# =============================================================================
FROM php:8.5.2-fpm-alpine AS base

RUN apk add --no-cache \
    postgresql-libs libzip icu-libs oniguruma \
    libstdc++ grpc protobuf \
    curl fcgi

# Copy pre-built extensions from extensions stage
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/99-errata.ini

WORKDIR /app
COPY --from=builder /app /app

RUN mkdir -p /app/var/cache /app/var/log /app/storage \
    && chown -R www-data:www-data /app/var /app/storage /app/public

# =============================================================================
# Stage 4: Web (Nginx + PHP-FPM with Supervisor)
# =============================================================================
FROM base AS web

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
# Stage 5: Worker (Messenger consumer)
# =============================================================================
FROM base AS worker

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# No port exposed - worker doesn't serve HTTP
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"]
