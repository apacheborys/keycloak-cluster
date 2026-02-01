FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev netcat-openbsd $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && { \
        echo "xdebug.mode=debug,develop"; \
        echo "xdebug.start_with_request=yes"; \
        echo "xdebug.client_host=host.docker.internal"; \
        echo "xdebug.client_port=9003"; \
      } > /usr/local/etc/php/conf.d/99-xdebug.ini \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony*/bin/symfony /usr/local/bin/symfony \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /root/.symfony*

WORKDIR /app

COPY symfony-entrypoint.sh /usr/local/bin/symfony-entrypoint.sh
RUN chmod +x /usr/local/bin/symfony-entrypoint.sh

CMD ["/usr/local/bin/symfony-entrypoint.sh"]
