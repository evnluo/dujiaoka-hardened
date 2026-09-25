# Final PHP 7.4 patch line. Still EOL; deliberately no major framework migration.
FROM ghcr.io/apocalypsor/dujiaoka@sha256:c70109d1c18fd4b936241dce2d3cc84bbd25570fb3a285ce8c9743b22ef692a4 AS composer-source
FROM php:7.4.33-fpm-alpine@sha256:0aeb129a60daff2874c5c70fcd9d88cdf3015b4fb4cc7c3f1a32a21e84631036 AS php-base
RUN apk add --no-cache nginx supervisor curl git unzip icu-libs libzip libpng libjpeg-turbo freetype oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath pdo_mysql mysqli gd intl zip pcntl opcache \
    && pecl install igbinary-3.2.2 \
    && docker-php-ext-enable igbinary \
    && pecl install --configureoptions 'enable-redis-igbinary="yes"' redis-5.3.4 \
    && docker-php-ext-enable redis \
    && apk del .build-deps
COPY --from=composer-source /usr/bin/composer /usr/local/bin/composer
RUN addgroup -g 1000 application && adduser -D -u 1000 -G application application
WORKDIR /dujiaoka
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM php-base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader

FROM php-base AS runtime
COPY . /dujiaoka/
COPY --from=vendor /dujiaoka/vendor /dujiaoka/vendor
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
    && chown -R application:application storage bootstrap/cache \
    && nginx -t && php-fpm -t
ARG VCS_REF
LABEL org.opencontainers.image.source="https://github.com/evnluo/dujiaoka-hardened" \
      org.opencontainers.image.revision=$VCS_REF \
      org.opencontainers.image.description="Oknice payment hardening with final PHP 7.4 and Laravel 6 patches; still EOL"
EXPOSE 80
ENTRYPOINT ["/start.sh"]

FROM runtime AS security-test
ENTRYPOINT ["php", "tests/security.php"]

FROM runtime AS final
