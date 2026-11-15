# ---------------------------------------------------------------------------
# Imagem do Billing Service (php-fpm). O nginx roda como sidecar no pod (k8s)
# e faz fastcgi para esta imagem, que expõe só o fpm.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    APP_DEBUG=false

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && docker-php-ext-install bcmath intl mbstring pcntl zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/apcu.ini /usr/local/etc/php/conf.d/zz-apcu.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# Swagger gerado no build (storage/ é efêmero por pod). Só lê as anotações;
# o APP_KEY é descartável, usado apenas para o artisan bootar.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan l5-swagger:generate \
    && chown -R www-data:www-data storage/api-docs

EXPOSE 9000

CMD ["php-fpm", "--nodaemonize"]
