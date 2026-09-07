# The image every PHP service in this project runs from.
#
# It carries the application. That is the whole point of the multi-stage build
# below: before it, /var/www/html was empty in the image and the code arrived
# from a bind mount, which meant the containers were separated at runtime but
# were never independently deployable artefacts — backend, horizon and scheduler
# all read one mutable directory on the host, so none of them could be shipped
# or rolled back without moving the other two in the same instant.
#
# Development still bind-mounts the working tree over the baked copy, so the
# day-to-day loop is unchanged. What changed is that the image is now a thing
# that can be deployed on its own.

# ─── Stage: base — the PHP runtime this project needs, and nothing else ───
FROM php:8.4-fpm-alpine AS base

# Build dependencies are installed and removed within this stage, so the layers
# the later stages inherit never carry a compiler.
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    pcntl \
    posix \
    bcmath \
    opcache \
    intl \
    zip \
    exif

RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

RUN apk del $PHPIZE_DEPS

# Composer stays in the final image on purpose: the quality gate and CI both run
# `composer install` inside a running backend container against the bind-mounted
# tree, because the suite needs the dev dependencies this image deliberately
# excludes.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/backend/php.ini $PHP_INI_DIR/conf.d/99-custom.ini

WORKDIR /var/www/html

# ─── Stage: vendor — production dependencies, resolved from the lock file ───
#
# The manifests are copied before the application so that editing a controller
# does not invalidate the dependency layer. Autoloading is deferred until the
# source is present, because the optimised classmap has to see it.
FROM base AS vendor

COPY backend/composer.json backend/composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

COPY backend/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ─── Stage: app — the deployable image ───
FROM base AS app

COPY backend/ /var/www/html
COPY --from=vendor /var/www/html/vendor /var/www/html/vendor

# php-fpm drops to www-data, which must be able to write the two directories
# Laravel treats as writable state. Recorded as entry 10 of ADR 0029 for the
# bind-mounted case; this fixes it for the image itself.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
