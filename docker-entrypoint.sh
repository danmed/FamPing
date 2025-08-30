#!/bin/sh

# This directory is our persistent data volume.
# We ensure it exists and is owned by the web server user.
mkdir -p /data
chown -R www-data:www-data /data

# Start the background checking script in a loop.
(while true; do
    # Wait a moment for the server to be ready before the first check
    sleep 10
    php /var/www/html/check_monitors.php
    sleep 300
done) &

# Start Apache in the foreground. This is the main process for the container.
apache2-foreground
