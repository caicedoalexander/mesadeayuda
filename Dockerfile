FROM php:8.5-fpm

# Install system dependencies + nginx
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    curl \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application
COPY . .
RUN composer dump-autoload --optimize --no-dev

# Generate app_local.php from example (uses env vars at runtime)
RUN cp config/app_local.example.php config/app_local.php

# Create necessary directories and set permissions
RUN mkdir -p logs tmp/cache/models tmp/cache/persistent tmp/cache/views \
    tmp/sessions webroot/uploads/tickets webroot/uploads/compras config/google \
    && chown -R www-data:www-data tmp logs webroot/uploads config/google \
    && chmod -R 775 tmp logs webroot/uploads config/google

# Nginx and PHP config
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
COPY docker/nginx/standalone.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8082

ENTRYPOINT ["entrypoint.sh"]
