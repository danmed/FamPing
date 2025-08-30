# Use an official PHP image with Apache
FROM php:8.2-apache

# Install required system dependencies and PHP extensions
# These are needed for the SQLite database, cURL (for notifications), and Proxmox integration
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl

# Copy the custom entrypoint script into the container
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Make the entrypoint script executable
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set the entrypoint to our custom script.
# This script will now run every time the container starts.
ENTRYPOINT ["docker-entrypoint.sh"]

# The default command for the php:apache image is to start Apache.
# We run this from our entrypoint script, so we can just set a default here.
CMD ["apache2-foreground"]
