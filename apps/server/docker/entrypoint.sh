#!/bin/sh
set -e

mkdir -p /app/var/cache /app/var/log /app/storage
chown -R www-data:www-data /app/var /app/storage

# Only run migrations for web process (avoid race conditions)
if [ "$1" = "supervisord" ] && [ -n "$DATABASE_URL" ]; then
    echo "Running migrations..."
    php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
fi

echo "Warming cache..."
php /app/bin/console cache:clear --env=prod --no-debug || true
chown -R www-data:www-data /app/var/cache

exec "$@"
