# PHP Version (digest pins the exact multi-platform image used for amd64 builds)
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm@sha256:cb0c75f188ef0a029e9c7f20a473cefac67e1e9fe3d257ed130fec141e95e244

# Database driver: mysql (default) or pgsql
ARG DB_EXT=mysql

# Runtime identity. 1000:1000 matches the deployment bind mounts owned by webadmin.
ARG RUNTIME_UID=1000
ARG RUNTIME_GID=1000

# System dependencies & PHP extensions (GD, ExifTool, ImageMagick CLI, Zip, Intl, DB driver)
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libimage-exiftool-perl \
    imagemagick \
    --no-install-recommends \
    && if [ "${DB_EXT}" = "pgsql" ]; then apt-get install -y --no-install-recommends libpq-dev; fi \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) intl gd zip exif pdo_${DB_EXT} pcntl bcmath \
    && rm -r /var/lib/apt/lists/* \
    && groupmod --gid "${RUNTIME_GID}" www-data \
    && usermod --uid "${RUNTIME_UID}" --gid "${RUNTIME_GID}" www-data

WORKDIR /var/www/html
RUN mkdir -p /var/www/photos /var/www/ftp \
    && chown -R www-data:www-data /var/www/html /var/www/photos /var/www/ftp

# PHP Config (higher limits for image-intensive apps)
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/php-base.ini \
    && echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/php-base.ini

# PHP-FPM, Artisan, queue workers and scheduler all share the non-root identity.
USER www-data
