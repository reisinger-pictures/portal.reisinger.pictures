# PHP Version (tag pins the minor series; digest pins would freeze to one build
# and need manual updates per patch release)
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm

# Database driver: mysql (default) or pgsql
ARG DB_EXT=mysql

# Runtime identity. The image's own user is www-data 1000:1000 (the CI and E2E
# jobs override it with `--user root` for the mounted workspace). Production
# does not use it: deployment/docker-compose.yml runs every service that writes
# the website tree as `user: "1002:82"` (D-1, owner decision 2026-10-25), which
# overrides this build-time identity.
#
# No passwd entry for 1002 is needed, and none is created here on purpose:
#   - php-fpm resolves the pool's `user`/`group` only while running as root
#     (fpm_unix.c: "the 'user' directive is ignored when FPM is not running as
#     root"); this container never is, so it runs as the current uid either way;
#   - Laravel and the backend commands never call getpwuid/posix_getpwuid, and
#     `id -u`/`id -g` in the start guard compare numbers, not names;
#   - composer_init and sftpgo already run as 1002:82 from images that have no
#     such entry, and both work in production.
# Adding an entry would be an untested second identity source; the numeric
# `user:` in compose stays the single one.
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
COPY backend-supervisor.sh /usr/local/bin/portal-backend-supervisor
COPY validate-production-env.sh /usr/local/bin/validate-production-env
RUN chmod 0755 /usr/local/bin/portal-backend-supervisor /usr/local/bin/validate-production-env \
    && mkdir -p /var/www/photos /var/www/ftp \
    && chown -R www-data:www-data /var/www/html /var/www/photos /var/www/ftp

# PHP Config (higher limits for image-intensive apps)
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/php-base.ini \
    && echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "session.upload_progress.enabled = Off" >> /usr/local/etc/php/conf.d/php-base.ini

# PHP-FPM, Artisan, queue workers and scheduler all share the non-root identity.
USER www-data
