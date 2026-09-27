# Source-only modern shop runtime. Pin platform indexes; never copy host runtime data.
FROM composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 AS composer
FROM php:8.5-fpm-alpine@sha256:ef8e5dac4f891df1e452a7b89b01de01d20d25788d2c3dd0f8ce137ac546c47d AS php-base
RUN apk add --no-cache nginx supervisor curl git unzip icu-libs libzip libpng libjpeg-turbo freetype oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath pdo_mysql gd intl zip pcntl \
    && apk del .build-deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN addgroup -g 1000 application && adduser -D -u 1000 -G application application
WORKDIR /dujiaoka
RUN apk add --no-cache --virtual .redis-build-deps $PHPIZE_DEPS \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .redis-build-deps
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM php-base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader

FROM node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS assets
WORKDIR /dujiaoka
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY --from=vendor /dujiaoka/vendor ./vendor
COPY resources ./resources
COPY app ./app
COPY vite.config.js ./
RUN pnpm build

FROM php-base AS runtime
COPY . /dujiaoka/
COPY --from=vendor /dujiaoka/vendor /dujiaoka/vendor
COPY --from=assets /dujiaoka/public/build /dujiaoka/public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-application.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/start.sh /start.sh
ENV INSTALL=false APP_ENV=production APP_DEBUG=false
RUN chmod 755 /start.sh \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /run/nginx \
    && rm -f .env .env.backup bootstrap/cache/config.php bootstrap/cache/routes*.php \
    && composer dump-autoload --no-dev --no-scripts --optimize \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && chown -R application:application storage bootstrap/cache \
    && nginx -t && php-fpm -t
ARG VCS_REF
LABEL org.opencontainers.image.source="https://github.com/evnluo/dujiaoka-hardened" \
      org.opencontainers.image.revision=$VCS_REF \
      org.opencontainers.image.description="Evan's Shop: modern Laravel, compact Filament admin and verified payment fulfillment"
EXPOSE 80
ENTRYPOINT ["/start.sh"]

FROM runtime AS app-test
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress
ENTRYPOINT ["php", "vendor/bin/phpunit", "tests/Feature"]

FROM runtime AS security-test
ENTRYPOINT ["php", "tests/security.php"]

FROM runtime AS final
