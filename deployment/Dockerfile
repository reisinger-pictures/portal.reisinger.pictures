# PHP Version
ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm

# Database driver: mysql (default) or pgsql
ARG DB_EXT=mysql

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
    && rm -r /var/lib/apt/lists/*

WORKDIR /var/www/html
RUN chown -R www-data:www-data /var/www/html

# PHP Config (higher limits for image-intensive apps)
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/php-base.ini \
    && echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/php-base.ini
