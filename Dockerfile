# PHP-FPM with Nginx for Nominatim API
FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    postgresql-dev \
    curl \
    bash \
    shadow

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Create nginx user and set permissions
RUN addgroup -g 82 -S www-data 2>/dev/null || true && \
    adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true

# Create necessary directories
RUN mkdir -p /var/www/html/nominatim-api \
    /var/log/nginx \
    /var/log/php-fpm \
    /run/nginx \
    /run/php-fpm && \
    chown -R www-data:www-data /var/www/html /var/log/nginx /var/log/php-fpm /run/nginx /run/php-fpm

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# Copy PHP-FPM configuration
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf

# Copy PHP API files
COPY index.php /var/www/html/nominatim-api/
COPY test.php /var/www/html/nominatim-api/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html/nominatim-api && \
    chmod -R 755 /var/www/html/nominatim-api && \
    chmod 644 /var/www/html/nominatim-api/*.php

# Health check script
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

# Expose ports
EXPOSE 8181

# Start script
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

CMD ["/usr/local/bin/start.sh"]