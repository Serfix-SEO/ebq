# ebq-app:8.3 — PHP runtime for box D (web FPM + Horizon + scheduler + fleet).
#
# Why a container for PHP: box D runs Ubuntu 26.04 whose native PHP is 8.5 —
# the app requires 8.3 (8.5 breaks Laravel queue closures; see
# infra/deployment-and-queues.md). This is the SAME approach and extension set
# as the long-standing worker image `ebq-worker:8.3` on box B / ephemeral
# boxes, rebased onto php:8.3-fpm so one image serves web and workers.
# The app is bind-mounted from /var/www/ebq — the image holds no code.
#
# Build (on the box):  docker build -t ebq-app:8.3 docker/app
FROM php:8.3-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev git unzip openssh-client rsync; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath intl zip gd pcntl opcache; \
    pecl install redis igbinary && docker-php-ext-enable redis igbinary; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Opcache: timestamps validated (code is bind-mounted; deploys must not
# require container restarts — deliberately unlike box A's baked SHM setup).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=40000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/zz-ebq-opcache.ini; \
    { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=33M'; \
        echo 'max_execution_time=400'; \
    } > /usr/local/etc/php/conf.d/zz-ebq.ini

WORKDIR /var/www/ebq
