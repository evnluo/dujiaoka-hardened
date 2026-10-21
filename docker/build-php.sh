#!/bin/sh
# Alpine FPM build adapted from docker-library/php commit
# f6048b5f6e3c2202ee082665a4ccc0d90de291a3, 8.5/alpine3.23/fpm/Dockerfile.
# Rebuild the pinned base in place while upstream's 8.5.12 indexes are empty.
set -eux

docker-php-source delete
curl -fsSL -o /usr/src/php.tar.xz "$PHP_URL"
printf '%s  %s\n' "$PHP_SHA256" /usr/src/php.tar.xz | sha256sum -c -
apk add --no-cache --virtual .php-build-deps \
    $PHPIZE_DEPS argon2-dev coreutils curl-dev gnu-libiconv-dev libsodium-dev \
    libxml2-dev linux-headers oniguruma-dev openssl-dev readline-dev sqlite-dev
# Match upstream: musl's iconv must not shadow GNU libiconv.
rm -f /usr/include/iconv.h
export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS"
export PHP_BUILD_PROVIDER='https://github.com/evnluo/dujiaoka-hardened' PHP_UNAME='Linux - Docker'
docker-php-source extract
cd /usr/src/php
gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"
./configure \
    --build="$gnuArch" \
    --sysconfdir="${PHP_INI_DIR%/php}" \
    --with-config-file-path="$PHP_INI_DIR" \
    --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
    --enable-option-checking=fatal \
    --with-mhash --with-pic --enable-mbstring --enable-mysqlnd \
    --with-password-argon2 --with-sodium=shared \
    --with-pdo-sqlite=/usr --with-sqlite3=/usr \
    --with-curl --with-iconv=/usr --with-openssl --with-readline --with-zlib \
    --disable-phpdbg --with-pear --disable-cgi \
    --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data
make -j2
find . -type f -name '*.a' -delete
make install
find /usr/local -type f -perm '/0111' -exec sh -c 'strip --strip-all "$@" || :' -- '{}' +
make clean
cp php.ini-* "$PHP_INI_DIR/"
cd /
docker-php-source delete
runDeps="$(
    scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
        | tr ',' '\n' | sort -u \
        | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }'
)"
apk add --no-cache $runDeps
apk del --no-network .php-build-deps
pecl update-channels
rm -rf /tmp/pear /root/.pearrc
php --version
php-fpm --version
php -r 'exit(PHP_VERSION === getenv("PHP_VERSION") ? 0 : 1);'
php-fpm --version | grep -F "PHP $PHP_VERSION (fpm-fcgi)"
