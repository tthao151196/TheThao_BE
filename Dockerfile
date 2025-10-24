# ---- Build vendor (composer) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress

# ---- Runtime PHP + Apache ----
FROM php:8.2-apache
WORKDIR /var/www/html

RUN a2enmod rewrite
RUN docker-php-ext-install pdo pdo_mysql

# copy code & vendor
COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /app/composer.* /var/www/html/

# Apache -> public và bật AllowOverride
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
 && printf "\n<Directory /var/www/html/public/>\n\tAllowOverride All\n</Directory>\n" >> /etc/apache2/apache2.conf

# quyền cho storage/bootstrap
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
 && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# entrypoint khởi động
COPY ./docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
CMD ["/usr/local/bin/entrypoint.sh"]
