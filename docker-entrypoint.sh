#!/bin/sh

# This script is the entrypoint for the Docker container.
# It handles permissions and starts both the background checker and the web server.

# Set the path to the database file
DB_FILE="/data/monitor.db"

# Ensure the /data directory exists and has the correct permissions for the web server user (www-data)
mkdir -p /data
chown -R www-data:www-data /data
chmod -R 775 /data

echo "Permissions set on /data directory."

# --- Start the background monitor checking process ---
(
    echo "Starting background check loop..."
    # Loop indefinitely
    while true; do
        # Default interval in seconds (5 minutes)
        INTERVAL=300 
        
        # If the database file exists, try to read the interval setting from it
        if [ -f "$DB_FILE" ]; then
            # Use sqlite3 client to query the database. Redirect errors to /dev/null.
            # This is safer than trying to parse it with shell tools.
            QUERY_RESULT=$(sqlite3 "$DB_FILE" "SELECT value FROM settings WHERE key = 'check_interval_seconds';" 2>/dev/null)
            
            # Check if the query result is a valid number
            if [ -n "$QUERY_RESULT" ] && [ "$QUERY_RESULT" -eq "$QUERY_RESULT" ] 2>/dev/null; then
                INTERVAL=$QUERY_RESULT
            fi
        fi
        
        # Run the PHP check script
        php /var/www/html/check_monitors.php
        
        # Sleep for the configured interval
        echo "Check complete. Sleeping for $INTERVAL seconds..."
        sleep "$INTERVAL"
    done
) & # The '&' runs this whole block in the background

# Start the Apache web server in the foreground. This is the main process for the container.
echo "Starting Apache web server..."
apache2-foreground

