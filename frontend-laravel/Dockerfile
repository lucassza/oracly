FROM php:8.4-cli-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libsqlite3-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pdo_sqlite \
        pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/oracly-memory.ini \
    && echo 'max_execution_time=120' >> /usr/local/etc/php/conf.d/oracly-memory.ini

COPY docker/entrypoint.sh /usr/local/bin/oracly-entrypoint
RUN chmod +x /usr/local/bin/oracly-entrypoint

EXPOSE 8000

ENTRYPOINT ["oracly-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
