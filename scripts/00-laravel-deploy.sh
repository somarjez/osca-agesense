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

echo "Linking storage..."
php artisan storage:link || true

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "--- debug: route/env state ---"
php artisan config:show app.env --no-ansi 2>&1
php artisan config:show app.debug --no-ansi 2>&1
echo "Total registered routes:"
php artisan route:list --no-ansi 2>&1 | wc -l
echo "Dashboard route lookup:"
php artisan route:list --path=dashboard --no-ansi 2>&1
echo "Login route lookup:"
php artisan route:list --path=login --no-ansi 2>&1
echo "Sessions table check:"
php artisan db:table sessions --no-ansi 2>&1 | head -20
echo "--- end debug ---"

echo "Caching views..."
php artisan view:cache

echo "Running migrations..."
php artisan migrate --force
