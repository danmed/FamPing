# Use an official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies, PHP extensions, and the `setcap` utility
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    iputils-ping \
    libcap2-bin \
    && docker-php-ext-install pdo_sqlite curl

# Grant the ping utility the necessary capability to be run by non-root users.
# This is the key fix for allowing ping to work correctly from the web server.
RUN setcap cap_net_raw+ep /bin/ping

# Copy the entrypoint script and make it executable
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy application files
COPY . /var/www/html/

# Set the entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]

# Expose port 80 for the Apache web server
EXPOSE 80
