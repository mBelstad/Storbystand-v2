FROM wordpress:6.6.1-php8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        less \
        mariadb-client \
        unzip \
        git \
        nano \
    && rm -rf /var/lib/apt/lists/*

# Install WP-CLI globally
RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

ENV PHP_MEMORY_LIMIT=512M
