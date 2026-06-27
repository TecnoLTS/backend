FROM php:8.5-fpm-alpine

# Keep the base packages current and install only runtime libraries permanently.
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache \
        ca-certificates \
        freetype \
        libjpeg-turbo \
        libpng \
        libxml2 \
        openssl \
        postgresql-libs \
        libzip \
        tzdata; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        postgresql-dev \
        libxml2-dev \
        libzip-dev \
        git \
        unzip

# Configurar zona horaria para Ecuador
ENV TZ=America/Guayaquil
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo_pgsql soap zip

# Configurar zona horaria de PHP
RUN { \
        echo "date.timezone = America/Guayaquil"; \
        echo "expose_php = Off"; \
        echo "display_errors = Off"; \
        echo "log_errors = On"; \
    } > /usr/local/etc/php/conf.d/app.ini \
    && { \
        echo "[www]"; \
        echo "clear_env = no"; \
    } > /usr/local/etc/php-fpm.d/zz-env.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy existing application directory contents
COPY . .

# Install PHP dependencies at build time, then remove build-only tooling.
RUN set -eux; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; \
    COMPOSER_ALLOW_SUPERUSER=1 composer clear-cache; \
    rm -f /usr/bin/composer; \
    apk del .build-deps; \
    mkdir -p \
        /var/www/html/public/uploads/products \
        /var/www/html/storage/logs \
        /var/www/html/storage/billing/cache \
        /var/www/html/storage/billing/certs \
        /var/www/html/storage/billing/logs \
        /var/www/html/storage/billing/pdf/rides \
        /var/www/html/storage/billing/xml/autorizados \
        /var/www/html/storage/billing/xml/firmados \
        /var/www/html/storage/billing/xml/generados; \
    chown -R www-data:www-data /var/www/html/public/uploads /var/www/html/storage; \
    find /var/www/html/public/uploads -type d -exec chmod 755 {} +; \
    find /var/www/html/public/uploads -type f -exec chmod 644 {} +; \
    find /var/www/html/storage -type d -exec chmod 750 {} +; \
    find /var/www/html/storage -type f -exec chmod 640 {} +

EXPOSE 9000

USER www-data

CMD ["php-fpm"]
