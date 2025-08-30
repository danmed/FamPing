# Use an official PHP image with Apache for the web server
FROM php:8.2-apache

# Install system dependencies and the required PHP extensions for FamPing
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl

# Set the working directory inside the container
WORKDIR /var/www/html

# Note: We no longer need to copy files here.
# The docker-compose.yml file will mount the project directory directly.
# This is better for development as changes are reflected instantly.
