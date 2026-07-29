# Build frontend assets (Vite) separately — the PHP base image below has no Node.
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
RUN npm run build

# Render's documented base image for Laravel-on-Docker: bundles Nginx + PHP-FPM + Supervisor,
# and runs numbered scripts/*.sh at container start when RUN_SCRIPTS=1.
# https://render.com/docs/deploy-php-laravel-docker
FROM richarvey/nginx-php-fpm:3.1.6

COPY . .
COPY --from=assets /app/public/build /var/www/html/public/build

# Image config
ENV SKIP_COMPOSER=1
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1
# NOTE: WEBROOT and PHP_CATCHALL are NOT set here, and setting them would do
# nothing — the base image applies both via a `sed` patch to the *default*
# Nginx config, but that patch runs BEFORE conf/nginx/nginx-site.conf gets
# copied over it (see start.sh), so it's silently discarded either way.
# Both concerns (webroot path, front-controller fallback) are instead
# hardcoded directly in conf/nginx/nginx-site.conf.

# Laravel config — APP_DEBUG/LOG_CHANNEL fixed here since they must never
# be toggled on by accident in production; everything else (DB, keys, tokens)
# comes from Render's Environment Variables so it never lives in the image.
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

# Allow composer to run as root inside the container
ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["/start.sh"]
