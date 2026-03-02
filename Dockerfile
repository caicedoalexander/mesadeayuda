FROM php:8.4-fpm

# Install system dependencies + nginx
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libicu-dev \
    nginx \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Copy config template to app_local.php (uses environment variables)
RUN cp config/app_local.example.php config/app_local.php

# Install application dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create necessary directories and set permissions
RUN mkdir -p logs tmp/cache tmp/sessions webroot/uploads/tickets webroot/uploads/compras webroot/uploads/pqrs config/google \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 logs tmp webroot/uploads config/google

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Configure Nginx
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
COPY docker/nginx/standalone.conf /etc/nginx/sites-enabled/default

# Entrypoint: start php-fpm in background, nginx in foreground
RUN printf '#!/bin/sh\nphp-fpm -D\nnginx -g "daemon off;"\n' > /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

CMD ["/usr/local/bin/entrypoint.sh"]
