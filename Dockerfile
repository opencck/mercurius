# Mercurius application image: PHP 8.2 CLI with the extensions AMPHP and PostgreSQL need.
# The base is pinned by digest: a rebuild gives the same image; a newer base is taken on purpose, by changing it here.
FROM php:8.2-cli-bookworm@sha256:2867ae7fa1f7f248b94897c81d9154255526aec97b8493de708178700ebc147a AS base

# No pecl-ev: amphp/postgres over ext-pgsql refuses to run on the ev loop driver.
# Revolt falls back to its stream_select driver, which is enough at the current load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev git unzip \
    && docker-php-ext-install pgsql pcntl sockets zip \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

# pg_dump and pg_restore of the server's version (17) for the archive of the agent: bookworm ships 15, which cannot
# dump a 17 server, so the client comes from the PostgreSQL project's own repository.
# spec: brief/operations.md#экспорт-и-импорт-агента
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main' > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-17 \
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

COPY bootstrap ./bootstrap
COPY src ./src
COPY migrations ./migrations
COPY skills ./skills
COPY bin/doctor.php ./bin/doctor.php
# the names of the settings that go into the archive of the agent
COPY .env.example ./

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

RUN useradd --system --uid 10001 --home /app mercurius \
    && mkdir -p /app/storage \
    && chown -R mercurius /app/storage
USER mercurius

EXPOSE 8080
STOPSIGNAL SIGTERM
CMD ["php", "bootstrap/index.php"]

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
# PHP 8.2 with Composer, Python 3, Node.js with npm, Go. Documents and video are modules (media-docs, media-video), not here. The executor runs as root with a minimal capability set
# (compose) and does every participant operation as that participant's Unix user.
# spec: brief/agent-loop.md#песочница
FROM php:8.2-cli-bookworm@sha256:2867ae7fa1f7f248b94897c81d9154255526aec97b8493de708178700ebc147a AS sandbox
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl ca-certificates unzip util-linux procps python3 python3-pip python3-venv nodejs npm \
    && docker-php-ext-install pcntl sockets \
    && rm -rf /var/lib/apt/lists/* \
    && echo 'zend.assertions=-1' > /usr/local/etc/php/conf.d/mercurius.ini
COPY --from=golang:1.23-bookworm /usr/local/go /usr/local/go
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV PATH=/usr/local/go/bin:$PATH
WORKDIR /app
COPY --from=base /app/vendor ./vendor
COPY composer.json ./
COPY bootstrap/sandbox.php ./bootstrap/sandbox.php
COPY src ./src
RUN mkdir -p /workspace && chmod 0711 /workspace
EXPOSE 9000
STOPSIGNAL SIGTERM
# /proc with hidepid: a participant sees only own processes, root (gid 0) all of them, to stop what a command left
CMD ["sh", "-c", "mount -o remount,hidepid=2,gid=0 /proc || echo 'hidepid is not set: /proc stays open' >&2; exec php bootstrap/sandbox.php"]

# Sandbox tests (users, processes, files): docker compose --profile test run --rm sandbox-test
FROM sandbox AS sandbox-test
COPY --from=test /app/vendor ./vendor
COPY phpunit.xml.dist ./
COPY test ./test
CMD ["sh", "-c", "mount -o remount,hidepid=2,gid=0 /proc || echo 'hidepid is not set' >&2; exec composer test-sandbox"]
