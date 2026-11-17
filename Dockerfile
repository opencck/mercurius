# Mercurius application image: PHP 8.2 CLI with the extensions AMPHP and PostgreSQL need.
FROM php:8.2-cli-bookworm AS base

# No pecl-ev: amphp/postgres over ext-pgsql refuses to run on the ev loop driver.
# Revolt falls back to its stream_select driver, which is enough at the current load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev git unzip \
    && docker-php-ext-install pgsql pcntl sockets zip \
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
# Packages are git submodules installed as Composer path repositories (copied, not symlinked).
COPY packages ./packages
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

COPY index.php ./
COPY src ./src
COPY migrations ./migrations
COPY bin/doctor.php ./bin/doctor.php

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
RUN mkdir -p .phpunit.cache var && chown -R mercurius .phpunit.cache var
USER mercurius
CMD ["composer", "test-integration"]

# Sandbox: where the agent runs commands and keeps files. Runtimes the agent can build tools on (owner decision):
# PHP 8.2 with Composer, Python 3 (with python-pptx, python-docx, openpyxl: presentations, documents, tables without a download), Node.js with npm, Go. The executor runs as root with a minimal capability set
# (compose) and does every participant operation as that participant's Unix user.
# spec: BRIEF.md#песочница
FROM php:8.2-cli-bookworm AS sandbox
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl ca-certificates unzip util-linux procps python3 python3-pip python3-venv nodejs npm \
        python3-docx python3-openpyxl python3-lxml python3-pil python3-xlsxwriter python3-typing-extensions poppler-utils \
    # python-pptx is not packaged in bookworm: from PyPI, its dependencies from the lines above
    && pip3 install --break-system-packages --no-cache-dir --no-deps python-pptx==1.0.2 \
    && docker-php-ext-install pcntl sockets \
    && rm -rf /var/lib/apt/lists/* \
    && echo 'zend.assertions=-1' > /usr/local/etc/php/conf.d/mercurius.ini
COPY --from=golang:1.23-bookworm /usr/local/go /usr/local/go
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV PATH=/usr/local/go/bin:$PATH
WORKDIR /app
COPY --from=base /app/vendor ./vendor
COPY composer.json sandbox.php ./
COPY src ./src
# spec: BRIEF.md#входящие-вложения — the text of an incoming document comes out here, as the participant
COPY bin/extract_text.py /opt/mercurius/extract_text.py
RUN mkdir -p /workspace && chmod 0711 /workspace
EXPOSE 9000
STOPSIGNAL SIGTERM
CMD ["php", "sandbox.php"]

# Sandbox tests (users, processes, files): docker compose --profile test run --rm sandbox-test
FROM sandbox AS sandbox-test
COPY --from=test /app/vendor ./vendor
COPY phpunit.xml.dist ./
COPY test ./test
CMD ["composer", "test-sandbox"]
