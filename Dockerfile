FROM dunglas/frankenphp:1.12.4-php8.5

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
