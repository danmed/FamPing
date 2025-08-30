#!/bin/sh

# Set the path to the database file
DB_FILE="/var/www/html/monitor.db"

# Fix permissions on the database file if it exists.
# This runs every time the container starts, ensuring the web server can always write to it.
if [ -f "$DB_FILE" ]; then
    chown www-data:www-data "$DB_FILE"
fi

# Start the background checking script in a loop.
# The output is redirected to Docker logs.
(while true; do
    php /var/www/html/check_monitors.php
    sleep 300
done) &

# Start Apache in the foreground. This is the main process for the container.
apache2-foreground
