# Reference env matching PRD §9.5: PHP 8.0, no opcache tuning, no object cache.
FROM wordpress:6.4-php8.0-apache

# Install minimal tooling needed for plugin dev/CI runs inside the container.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        less \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Composer (pinned to a stable 2.x).
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# WP-CLI.
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp

# Reference env: WP_MEMORY_LIMIT 256M, no opcache tuning, no object cache.
# We intentionally do NOT enable opcache or install Redis/Memcached.
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
        echo 'max_execution_time = 60'; \
    } > /usr/local/etc/php/conf.d/semantic-posts-reference.ini

WORKDIR /var/www/html
