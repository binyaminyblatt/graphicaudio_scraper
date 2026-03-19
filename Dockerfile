FROM php:8.3-apache

# Install APCu extension for caching
RUN pecl install apcu \
    && docker-php-ext-enable apcu

# Enable Apache mod_rewrite (if needed for routing)
RUN a2enmod rewrite

# Install openssl for key generation
RUN apt-get update && apt-get install -y openssl && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy the entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy the PHP application
COPY index.php /var/www/html/index.php

# Create directories for cache and covers
RUN mkdir -p /var/www/html/covers /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html/covers /var/www/html/cache

# Expose port 80
EXPOSE 80

# Use custom entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]