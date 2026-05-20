FROM php:8.1-cli-alpine

# Match shared-host reality: PHP 8.1 + OPcache + JIT (the realistic best case;
# if shared hosting disables JIT, fallback is ~2-3x slower — we'd note that).
RUN docker-php-ext-install opcache

RUN { \
        echo "memory_limit=256M"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.jit_buffer_size=128M"; \
        echo "opcache.jit=tracing"; \
    } > /usr/local/etc/php/conf.d/spike.ini

WORKDIR /spike
COPY spike.php .

# Default to N=5000; override via `docker run ... php spike.php <N>`
CMD ["php", "spike.php", "5000"]
