# Apsara Vimana — PHP 8.2 + Apache (Render / Docker)
FROM php:8.2-apache

RUN docker-php-ext-install opcache \
    && a2enmod rewrite headers \
    && printf '%s\n' \
      'opcache.enable=1' \
      'opcache.memory_consumption=128' \
      'opcache.validate_timestamps=1' \
      > /usr/local/etc/php/conf.d/opcache.ini

# Document root = repo root (index.html, api/, js/, imagenes/)
ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html

COPY . /var/www/html/

# cache escribible por Apache
RUN mkdir -p /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html/cache \
    && chmod -R 775 /var/www/html/cache

# Health / preferencias PHP
RUN printf '%s\n' \
  'upload_max_filesize=8M' \
  'post_max_size=8M' \
  'memory_limit=128M' \
  'max_execution_time=30' \
  > /usr/local/etc/php/conf.d/apsara.ini

EXPOSE 80
