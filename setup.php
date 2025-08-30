<?php
/*
================================================================================
File: setup.php
Description: Run this file ONCE to create the database and tables.
================================================================================
*/

try {
    // This path is for the Docker setup. For non-Docker, use __DIR__ . '/monitor.db'
    $db_path = '/data/monitor.db';
    $db = new PDO('sqlite:' . $db_path);

    // Set errormode to exceptions
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create monitors table
    $db->exec("CREATE TABLE IF NOT EXISTS monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        parent_id INTEGER,
        last_status TEXT DEFAULT 'pending', -- 'up', 'down', 'pending'
        last_check DATETIME,
        is_notifying INTEGER DEFAULT 0, -- Boolean 0 or 1
        FOREIGN KEY (parent_id) REFERENCES monitors(id) ON DELETE SET NULL
    )");

    // Create ping history table
    $db->exec("CREATE TABLE IF NOT EXISTS ping_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL,
        status TEXT NOT NULL, -- 'up' or 'down'
        check_time DATETIME NOT NULL,
        FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
    )");

    // Create settings table
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    
    // Populate default settings
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('discord_webhook_url', '')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('enable_email', '0')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('enable_discord', '0')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('email_to', '')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('smtp_host', '')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('smtp_port', '587')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('smtp_user', '')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('smtp_pass', '')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('smtp_encryption', 'tls')");
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('check_interval_seconds', '300')"); // NEW: Check interval

    // Create Proxmox servers table
    $db->exec("CREATE TABLE IF NOT EXISTS proxmox_servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        hostname TEXT NOT NULL,
        port INTEGER NOT NULL DEFAULT 8006,
        username TEXT NOT NULL,
        api_token TEXT NOT NULL,
        verify_ssl INTEGER NOT NULL DEFAULT 1, -- Boolean 0 or 1
        anchor_monitor_id INTEGER,
        FOREIGN KEY (anchor_monitor_id) REFERENCES monitors(id) ON DELETE SET NULL
    )");


    echo "<h1>Setup Complete!</h1>";
    echo "<p>Database 'monitor.db' and its tables have been created/updated successfully.</p>";
    echo "<p>You can now navigate to <a href='index.php'>index.php</a> to use the application.</p>";
    echo "<p><strong>IMPORTANT: For security, you should delete this 'setup.php' file now.</strong></p>";

} catch(PDOException $e) {
    // Print PDOException message
    echo "<h1>Setup Failed!</h1>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
