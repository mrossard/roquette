FROM dunglas/frankenphp:1.12.4-builder-php8.5 AS builder
COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

RUN apt-get update && \
    apt-get install -y --no-install-recommends git && \
    rm -rf /var/lib/apt/lists/*

RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp=./ \
    --with github.com/dunglas/frankenphp/caddy=./caddy/ \
    --with github.com/dunglas/mercure/caddy \
    --with github.com/dunglas/caddy-cbrotli \
    --with github.com/darkweak/souin/plugins/caddy \
    --with github.com/darkweak/storages/otter/caddy

FROM dunglas/frankenphp:1.12.4-php8.5

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

#force non-https, TZ, ...
ENV SERVER_NAME=":80" \
    TZ="Europe/Paris" \
    COMPOSER_ALLOW_SUPERUSER=1 \
    FRANKENPHP_CONFIG="worker ./public/index.php 8"

RUN apt-get update && \
    apt-get install -y --no-install-recommends supervisor tzdata && \
    install-php-extensions apcu zip opcache intl ldap soap pdo_pgsql pgsql pcntl sockets && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN curl -fsSL https://raw.githubusercontent.com/alexandre-daubois/ember/main/install.sh | sh

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# D’abord les fichiers de dépendances pour maximiser le cache Docker
COPY composer.json composer.lock symfony.lock ./

RUN composer install \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

# Puis le reste de l’application
COPY ./ ./

RUN php bin/console importmap:install
RUN php bin/console asset-map:compile

COPY --link --chmod=755 ./docker/entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link --chmod=644 ./docker/php-overrides.ini /usr/local/etc/php/conf.d/99-overrides.ini
COPY --link --chmod=644 ./docker/php-overrides-prod.ini /usr/local/etc/php/conf.d/99-overrides-prod.ini

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]

CMD ["/usr/bin/supervisord", "-c", "/app/docker/supervisor.conf"]
