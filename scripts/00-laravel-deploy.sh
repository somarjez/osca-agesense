#!/usr/bin/env bash
set -e

echo "Ensuring writable storage/cache directories exist..."
mkdir -p /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/testing \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache
chown -Rf nginx:nginx /var/www/html/storage /var/www/html/bootstrap/cache || echo "WARNING: chown to nginx:nginx failed"
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache || echo "WARNING: chmod failed"

echo "Running composer"
composer install --no-dev --optimize-autoloader --working-dir=/var/www/html

echo "OPcache config:"
php -i | grep -E 'opcache\.(enable|memory_consumption|validate_timestamps|max_accelerated_files)'

echo "Linking storage..."
php artisan storage:link || true

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Running migrations..."
php artisan migrate --force
