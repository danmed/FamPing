# Use an official PHP image with Apache
FROM php:8.2-apache

# Install required system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl

# Copy application files into the container's web root
COPY . /var/www/html/

# Set correct permissions for the web server to write to the database file
# This will allow the setup script to create the DB and the app to write to it.
RUN touch /var/www/html/monitor.db && \
    chown -R www-data:www-data /var/www/html

# Expose port 80 for the Apache web server
EXPOSE 80
