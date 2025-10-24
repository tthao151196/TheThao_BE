# ===== 1) Build vendor (composer) =====
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# BỎ qua kiểm tra extension ở stage build (ext sẽ được cài ở runtime)
RUN composer install \
    --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress \
    --ignore-platform-reqs

# ===== 2) Runtime: PHP 8.2 + Apache =====
FROM php:8.2-apache
WORKDIR /var/www/html

# Bật mod_rewrite
RUN a2enmod rewrite

# Cài dependency OS để build các extension
RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev \
      libjpeg62-turbo-dev libpng-dev libfreetype6-dev \
      libonig-dev \
      libsodium-dev \
    && rm -rf /var/lib/apt/lists/*

# Cấu hình & cài PHP extensions thường dùng cho Laravel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
      pdo pdo_mysql \
      gd zip bcmath exif sodium

# copy source + vendor
COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /app/composer.* /var/www/html/

# Apache DocumentRoot -> public & AllowOverride All (để .htaccess chạy)
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
 && printf "\n<Directory /var/www/html/public/>\n\tAllowOverride All\n</Directory>\n" >> /etc/apache2/apache2.conf

# Quyền cho storage/bootstrap
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
 && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint: chạy artisan khi container start (ENV đã có)
COPY ./docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
CMD ["/usr/local/bin/entrypoint.sh"]
