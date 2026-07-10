FROM node:22-alpine AS assets

WORKDIR /build

COPY site/package*.json site/vite.config.js ./
RUN npm install --ignore-scripts

COPY site/resources ./resources
COPY site/public ./public
RUN npm run build

FROM php:8.3-apache AS app

ENV APACHE_DOCUMENT_ROOT=/app/public
WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pdo_sqlite \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY site/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY site/composer.json site/composer.lock ./

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY site/ ./
COPY --from=assets /build/public/build ./public/build
COPY images ./legacy/images
COPY music ./legacy/music
COPY site/docker/entrypoint.sh /usr/local/bin/creative-ai-entrypoint

RUN mkdir -p \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod +x /usr/local/bin/creative-ai-entrypoint \
    && composer dump-autoload --optimize --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache public

EXPOSE 80

ENTRYPOINT ["creative-ai-entrypoint"]
CMD ["apache2-foreground"]
