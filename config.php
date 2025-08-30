<?php
/*
================================================================================
File: config.php
Description: Database configuration and helper functions.
================================================================================
*/

// --- Database Connection ---
function getDbConnection() {
    try {
        // This path is for the Docker setup. For non-Docker, use __DIR__ . '/monitor.db'
        $db_path = '/data/monitor.db';
        $db = new PDO('sqlite:' . $db_path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// --- Core Ping Function ---
/**
 * Pings a host and returns true if it's up, false if it's down.
 * @param string $host The IP address or hostname to ping.
 * @return bool True on success, false on failure.
 */
function pingHost($host) {
    // Detect the operating system to use the correct ping command.
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    if ($isWindows) {
        // Windows command: -n 1 (1 packet), -w 1000 (1-second timeout in ms)
        $command = "ping -n 1 -w 1000 " . escapeshellarg($host);
    } else {
        // Linux/macOS command: -c 1 (1 packet), -W 1 (1-second timeout)
        // Prepending 'sudo' to use the passwordless permission granted in the Dockerfile.
        $command = "sudo /bin/ping -c 1 -W 1 " . escapeshellarg($host);
    }

    // Execute the command
    exec($command, $output, $return_var);

    // Check the return status. 0 usually means success.
    return $return_var === 0;
}

// --- Notification Dispatcher ---
/**
 * Sends notifications to all enabled channels.
 * @param array $monitor The monitor that changed status.
 * @param string $status 'UP' or 'DOWN'.
 * @param PDO $db The database connection.
 */
function sendNotification($monitor, $status, $db) {
    try {
        $settings_stmt = $db->query("SELECT key, value FROM settings");
        $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $enable_email = $settings['enable_email'] ?? '0';
        $enable_discord = $settings['enable_discord'] ?? '0';
        
        if ($enable_email === '1') {
            sendEmailNotification($monitor, $status, $settings);
        }
        if ($enable_discord === '1') {
            sendDiscordNotification($monitor, $status, $settings['discord_webhook_url'] ?? '');
        }

    } catch (PDOException $e) {
        error_log("Failed to send notifications: " . $e->getMessage());
    }
}

// --- Email Notification Function (Now with SMTP) ---
/**
 * Sends an email notification using SMTP settings from the database.
 * @param array $monitor The monitor that changed status.
 * @param string $status 'UP' or 'DOWN'.
 * @param array $settings The application settings from the database.
 */
function sendEmailNotification($monitor, $status, $settings) {
    // Extract settings
    $to = $settings['email_to'] ?? '';
    $host = $settings['smtp_host'] ?? '';
    $port = $settings['smtp_port'] ?? 587;
    $user = $settings['smtp_user'] ?? '';
    $pass = $settings['smtp_pass'] ?? '';
    $encryption = $settings['smtp_encryption'] ?? 'tls';

    // Don't proceed if essential settings are missing
    if (empty($to) || empty($host)) {
        error_log("Email not sent: Recipient email or SMTP host is not configured.");
        return;
    }

    $from_email = $user ?: "monitor@" . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $from_name = 'PHPing';
    $subject = "PHPing Status Alert: {$monitor['name']} is {$status}";
    $body = "Hello,\r\n\r\n" .
            "This is an automated alert from PHPing.\r\n\r\n" .
            "Monitor Details:\r\n" .
            "- Name: {$monitor['name']}\r\n" .
            "- Host: {$monitor['ip_address']}\r\n" .
            "- Status: {$status}\r\n" .
            "- Time: " . date('Y-m-d H:i:s') . "\r\n\r\n" .
            "Thank you,\r\nPHPing";

    // Build headers
    $headers = "From: \"{$from_name}\" <{$from_email}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $smtp_protocol = ($encryption === 'ssl') ? 'ssl://' : '';
    $smtp = @fsockopen($smtp_protocol . $host, $port, $errno, $errstr, 15);

    if (!$smtp) {
        error_log("SMTP Connection Failed: [$errno] $errstr");
        return;
    }
    
    // Helper function to send commands and log responses
    function send_smtp_command($smtp, $cmd, &$log_array) {
        fputs($smtp, $cmd . "\r\n");
        $response = fgets($smtp, 512);
        // Optionally log for debugging: error_log("SMTP CMD: $cmd | RESP: $response");
        return $response;
    }

    fgets($smtp, 512); // Get server greeting

    send_smtp_command($smtp, "HELO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), $log);

    if ($encryption === 'tls') {
        send_smtp_command($smtp, "STARTTLS", $log);
        if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("Failed to start TLS encryption.");
            fclose($smtp);
            return;
        }
        send_smtp_command($smtp, "HELO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), $log);
    }
    
    if (!empty($user) && !empty($pass)) {
        send_smtp_command($smtp, "AUTH LOGIN", $log);
        send_smtp_command($smtp, base64_encode($user), $log);
        $response = send_smtp_command($smtp, base64_encode($pass), $log);
        if (substr($response, 0, 3) != "235") {
             error_log("SMTP Authentication failed: " . $response);
             fclose($smtp);
             return;
        }
    }

    send_smtp_command($smtp, "MAIL FROM: <{$from_email}>", $log);
    send_smtp_command($smtp, "RCPT TO: <{$to}>", $log);
    send_smtp_command($smtp, "DATA", $log);

    fputs($smtp, $headers . "\r\n" . $body . "\r\n.\r\n");
    fgets($smtp, 512); // Get response after DATA
    
    send_smtp_command($smtp, "QUIT", $log);
    fclose($smtp);
}


// --- Discord Webhook Notification Function ---
/**
 * @param string $webhookUrl The Discord webhook URL from the database.
 */
function sendDiscordNotification($monitor, $status, $webhookUrl) {
    if (empty($webhookUrl)) {
        error_log("Discord notification not sent: Webhook URL is not configured.");
        return;
    }
    $statusUpper = strtoupper($status);
    $color = ($statusUpper === 'UP') ? 3066993 : 15158332; // Green : Red

    $embed = [
        'title' => "PHPing Status: {$monitor['name']} is {$statusUpper}",
        'description' => "The monitor for `{$monitor['ip_address']}` has changed state.",
        'color' => $color,
        'timestamp' => date('c'),
        'footer' => ['text' => 'PHPing']
    ];

    $payload = json_encode(['username' => 'PHPing Alert', 'embeds' => [$embed]]);

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Discord Webhook Error: ' . curl_error($ch));
    }
    curl_close($ch);
}

// Best practice: Omit the closing PHP tag in files that contain only PHP code.
// This prevents accidental whitespace from being sent to the browser.

