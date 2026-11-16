# Mercurius application image: PHP 8.2 CLI with the extensions AMPHP and PostgreSQL need.
FROM php:8.2-cli-bookworm AS base

# No pecl-ev: amphp/postgres over ext-pgsql refuses to run on the ev loop driver.
# Revolt falls back to its stream_select driver, which is enough at the current load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pgsql pcntl sockets \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

RUN { \
        echo 'memory_limit=512M'; \
        echo 'opcache.enable_cli=1'; \
        echo 'zend.assertions=-1'; \
        echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/mercurius.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

COPY index.php ./
COPY src ./src
COPY migrations ./migrations

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

RUN useradd --system --uid 10001 --home /app mercurius \
    && mkdir -p /app/storage \
    && chown -R mercurius /app/storage
USER mercurius

EXPOSE 8080
STOPSIGNAL SIGTERM
CMD ["php", "index.php"]

# Image for running the test suites (unit and integration) on the server.
FROM base AS test
USER root
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts
COPY phpunit.xml.dist phpstan.neon.dist psalm.xml ./
COPY test ./test
USER mercurius
CMD ["composer", "test-integration"]
