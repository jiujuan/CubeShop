# CubeShop Laravel 应用镜像（PHP-FPM）
FROM php:8.5-fpm-alpine

# 系统依赖 + PHP 扩展（pdo_pgsql / bcmath / zip / opcache）
RUN apk add --no-cache \
        bash \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
    && apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 先复制依赖清单，利用镜像层缓存
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# 复制项目代码
COPY . .

# 目录权限
RUN chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --optimize

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
