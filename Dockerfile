# Vasuttam PMS: one image for the web server, the scheduler and the queue worker.
#   docker compose up -d        (see docker-compose.yml and README.md)

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node

# --- Build: Composer packages and the front-end (Wayfinder runs artisan during the Vite build) ---
FROM php:${PHP_VERSION}-cli-bookworm AS build

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl zip bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-exif

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && cp .env.example .env && php artisan key:generate --force \
    && npm run build \
    && npm prune --omit=dev --no-audit --no-fund \
    && rm -f .env

# --- Runtime: Apache with PHP, and Chromium for PDFs (invoices, prints, reports) ---
FROM php:${PHP_VERSION}-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        chromium fonts-liberation fonts-noto-core fonts-noto-color-emoji \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath exif pcntl opcache \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /app /var/www/html

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LARAVEL_PDF_CHROME_PATH=/usr/bin/chromium \
    LARAVEL_PDF_NO_SANDBOX=true

RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
