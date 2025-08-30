# Use an official PHP image with Apache
FROM php:8.2-apache

# Install required system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl

# Copy the entrypoint script and make it executable
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set ownership of the web root (good practice)
RUN chown -R www-data:www-data /var/www/html

# Set the entrypoint to our custom script
ENTRYPOINT ["docker-entrypoint.sh"]

# Expose port 80 for the Apache web server
EXPOSE 80
