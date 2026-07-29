#!/usr/bin/env bash
set -e

echo "Running composer"
composer install --no-dev --optimize-autoloader --working-dir=/var/www/html

echo "Ensuring writable storage/cache directories exist..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache
chown -Rf nginx:nginx storage bootstrap/cache || true
chmod -R 775 storage bootstrap/cache

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
