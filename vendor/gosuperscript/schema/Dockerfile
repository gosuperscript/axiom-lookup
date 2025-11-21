FROM serversideup/php:8.4-cli-alpine

USER root
RUN install-php-extensions intl bcmath xdebug
USER www-data
ENV XDEBUG_MODE=coverage
ENV PHP_OPCACHE_ENABLE=0
ENV PHP_MEMORY_LIMIT=1G

WORKDIR /app