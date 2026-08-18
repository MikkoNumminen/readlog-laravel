# The application container: PHP-FPM with the app installed, no web server. Nginx
# sits in front of it (see compose.yaml) and talks FastCGI to port 9000.
#
# .NET counterpart: the Dockerfile in readlog-dotnet, which is a two-stage build
# on the SDK image publishing onto mcr.microsoft.com/dotnet/aspnet. The shape is
# the same idea, dependencies first so the layer caches, then the source, then
# hand over to a non-root worker. The difference is that .NET compiles and this
# copies: there is no build output, the PHP files in the image are the program.

FROM php:8.4-fpm-alpine

# pdo_sqlite, sqlite3, mbstring, curl, dom, xml, fileinfo and opcache all ship in
# the base image. pdo_pgsql is the one addition, for the opt-in Postgres service
# and for anyone pointing DB_* at a Postgres of their own. libpq stays for
# runtime; the -dev headers are dropped again after the build. libpq-dev, not
# postgresql-dev: the latter pulls in a full LLVM toolchain on current Alpine for
# JIT headers this extension never uses.
RUN apk add --no-cache libpq su-exec unzip \
    && apk add --no-cache --virtual .build-deps libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apk del .build-deps

# Composer, installed from getcomposer.org with its published checksum rather than
# copied from the composer:2 image, so the build depends on one base image only.
RUN EXPECTED="$(wget -qO- https://composer.github.io/installer.sig)" \
    && wget -qO /tmp/composer-setup.php https://getcomposer.org/installer \
    && php -r "if (hash_file('sha384', '/tmp/composer-setup.php') !== '$EXPECTED') { echo 'Composer installer checksum mismatch'; exit(1); }" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet \
    && rm /tmp/composer-setup.php

# Production PHP settings. The image ships php.ini-development and -production
# side by side and neither active; opcache is compiled in but off until enabled.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
    } > "$PHP_INI_DIR/conf.d/zz-readlog.ini"

WORKDIR /var/www/html

# Dependencies before source, so a code change does not re-run composer install.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

# storage/ is a named volume in compose (see there for why). Everything the app
# writes at runtime lives under it: the SQLite database, sessions, the view cache,
# and logs when LOG_CHANNEL is not stderr.
VOLUME ["/var/www/html/storage"]

EXPOSE 9000

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
