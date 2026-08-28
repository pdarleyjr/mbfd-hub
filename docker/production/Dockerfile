# syntax=docker/dockerfile:1.7

# These OCI indexes are pinned so a rebuilt release cannot silently select a
# different base image. The deployed image itself is later selected by digest.
ARG UBUNTU_IMAGE=ubuntu:24.04@sha256:33ceb71981b602c1a7443a53469e4dba065f7503eab3078a2d7a57a2ab987517
ARG NODE_IMAGE=node:24-bookworm-slim@sha256:ba849c60be29959425b8734d57b8b4b7d56f98edd9504c9af091d5281095a71e
ARG COMPOSER_IMAGE=composer:2.8.4@sha256:72b9e4b2038558f7256e7f925495aa791c0ee764e1d351f3963b379b18b4c2f5

FROM ${UBUNTU_IMAGE} AS php-runtime-base

ARG WWWGROUP=1000

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80" \
    SUPERVISOR_PHP_USER="sail"

WORKDIR /var/www/html

# Keep the PHP extension set compatible with the currently deployed Sail 8.5
# runtime, but omit development-only debugging and browser tooling.
RUN set -eux; \
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime; \
    echo "${TZ}" > /etc/timezone; \
    echo "Acquire::http::Pipeline-Depth 0;" > /etc/apt/apt.conf.d/99mbfd; \
    echo "Acquire::http::No-Cache true;" >> /etc/apt/apt.conf.d/99mbfd; \
    echo "Acquire::BrokenProxy true;" >> /etc/apt/apt.conf.d/99mbfd; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libcap2-bin \
        libpng-dev \
        python3 \
        sqlite3 \
        supervisor \
        unzip \
        zip; \
    mkdir -p /etc/apt/keyrings; \
    curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' \
        | gpg --dearmor > /etc/apt/keyrings/ppa_ondrej_php.gpg; \
    echo 'deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main' \
        > /etc/apt/sources.list.d/ppa_ondrej_php.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        php8.5-bcmath \
        php8.5-cli \
        php8.5-curl \
        php8.5-gd \
        php8.5-igbinary \
        php8.5-imagick \
        php8.5-imap \
        php8.5-intl \
        php8.5-ldap \
        php8.5-mbstring \
        php8.5-memcached \
        php8.5-mongodb \
        php8.5-msgpack \
        php8.5-mysql \
        php8.5-pgsql \
        php8.5-readline \
        php8.5-redis \
        php8.5-soap \
        php8.5-sqlite3 \
        php8.5-xml \
        php8.5-zip; \
    setcap 'cap_net_bind_service=+ep' /usr/bin/php8.5; \
    groupadd --force --gid "${WWWGROUP}" sail; \
    useradd --create-home --shell /bin/bash --uid 1337 --gid sail sail; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/production/php.ini /etc/php/8.5/cli/conf.d/99-mbfd-production.ini
COPY docker/production/start-production-container /usr/local/bin/start-production-container

RUN chmod 0755 /usr/local/bin/start-production-container

EXPOSE 80/tcp
EXPOSE 8080/tcp

ENTRYPOINT ["/usr/local/bin/start-production-container"]

FROM ${COMPOSER_IMAGE} AS composer

FROM php-runtime-base AS vendor-build

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

# Composer authentication is optional for local public-dependency builds and is
# injected only as an ephemeral BuildKit secret in hosted CI.
RUN --mount=type=secret,id=composer_auth,required=false \
    set -eux; \
    if [ -s /run/secrets/composer_auth ]; then \
        export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(tr -d '\r\n' < /run/secrets/composer_auth)\"}}"; \
    fi; \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM ${NODE_IMAGE} AS frontend-build

WORKDIR /var/www/html

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --legacy-peer-deps

COPY . .

RUN SENTRY_AUTH_TOKEN='' \
    SENTRY_ORG='' \
    SENTRY_PROJECT_FRONTEND='' \
    VITE_SENTRY_DSN='' \
    VITE_SENTRY_RELEASE='' \
    npm run build \
    && cd resources/js/daily-checkout \
    && npm ci --ignore-scripts --legacy-peer-deps \
    && DAILY_CHECKOUT_OUT_DIR=../../../public/daily npm run build

FROM php-runtime-base AS application-build

ARG SOURCE_REVISION

COPY --from=vendor-build /var/www/html/vendor ./vendor
COPY . .
COPY --from=frontend-build /var/www/html/public/build ./public/build
COPY --from=frontend-build /var/www/html/public/daily ./public/daily

# Do not cache production configuration into the image: its values come from
# the mounted, host-managed Laravel environment file at runtime.
RUN set -eux; \
    test -n "${SOURCE_REVISION}"; \
    printf '%s\n' "${SOURCE_REVISION}" > .git-sha; \
    rm -f .build-time; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    touch /tmp/mbfd-image-build.sqlite; \
    APP_ENV=production \
    APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/tmp/mbfd-image-build.sqlite \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    MAIL_MAILER=array \
    BROADCAST_DRIVER=log \
    PULSE_ENABLED=false \
    SENTRY_LARAVEL_DSN='' \
    WORKGROUP_AI_ENABLED=false \
    php artisan package:discover --ansi; \
    APP_ENV=production \
    APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/tmp/mbfd-image-build.sqlite \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    MAIL_MAILER=array \
    BROADCAST_DRIVER=log \
    PULSE_ENABLED=false \
    SENTRY_LARAVEL_DSN='' \
    WORKGROUP_AI_ENABLED=false \
    php artisan filament:assets; \
    test -s public/build/manifest.json; \
    test -s public/daily/index.html

FROM php-runtime-base AS production

ARG SOURCE_REVISION

LABEL org.opencontainers.image.source="https://github.com/pdarleyjr/mbfd-hub" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"

COPY --from=application-build --chown=sail:sail /var/www/html /var/www/html

RUN set -eux; \
    test -n "${SOURCE_REVISION}"; \
    test "$(cat /var/www/html/.git-sha)" = "${SOURCE_REVISION}"; \
    rm -f /var/www/html/public/storage; \
    ln -s ../storage/app/public /var/www/html/public/storage; \
    chmod -R ug=rwX /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public; \
    test -s /var/www/html/public/build/manifest.json; \
    test -s /var/www/html/public/daily/index.html
