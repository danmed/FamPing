# Use an official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies, PHP extensions, and sudo
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    iputils-ping \
    sudo \
    && docker-php-ext-install pdo_sqlite curl

# Grant the www-data user passwordless sudo access ONLY for the ping command.
# This is a more robust alternative to setcap.
RUN echo "www-data ALL=(ALL) NOPASSWD: /bin/ping" > /etc/sudoers.d/ping_access

# Copy the entrypoint script and make it executable
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy application files
COPY . /var/www/html/

# Set the entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]

# Expose port 80 for the Apache web server
EXPOSE 80
