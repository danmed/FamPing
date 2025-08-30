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
        // Use __DIR__ to create an absolute path to the database
        $db_path = __DIR__ . '/monitor.db';
        $db = new PDO('sqlite:' . $db_path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// --- Core Ping Function ---
function pingHost($host) {
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    if ($isWindows) {
        $command = "ping -n 1 -w 1000 " . escapeshellarg($host);
    } else {
        $command = "ping -c 1 -W 1 " . escapeshellarg($host);
    }
    exec($command, $output, $return_var);
    return $return_var === 0;
}

// --- Notification Dispatcher ---
function sendNotification($monitor, $status, $db) {
    try {
        $settings = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (($settings['enable_email'] ?? '0') === '1') {
            sendEmailNotification($monitor, $status, $settings);
        }
        if (($settings['enable_discord'] ?? '0') === '1') {
            sendDiscordNotification($monitor, $status, $settings['discord_webhook_url'] ?? '');
        }
    } catch (PDOException $e) {
        error_log("Failed to send notifications: " . $e->getMessage());
    }
}

// --- Email Notification Function (SMTP) ---
function sendEmailNotification($monitor, $status, $settings) {
    $to = $settings['email_to'] ?? '';
    $host = $settings['smtp_host'] ?? '';
    if (empty($to) || empty($host)) {
        error_log("Email not sent: Recipient email or SMTP host is not configured.");
        return;
    }

    $port = $settings['smtp_port'] ?? 587;
    $user = $settings['smtp_user'] ?? '';
    $pass = $settings['smtp_pass'] ?? '';
    $encryption = $settings['smtp_encryption'] ?? 'tls';
    $from_email = $user ?: "monitor@" . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $from_name = 'FamPing';
    $subject = "FamPing Alert: {$monitor['name']} is {$status}";
    $body = "This is an automated alert from FamPing.\r\n\r\n" .
            "Monitor: {$monitor['name']} ({$monitor['ip_address']})\r\n" .
            "Status: {$status}\r\n" .
            "Time: " . date('Y-m-d H:i:s');

    $headers = "From: \"{$from_name}\" <{$from_email}>\r\nTo: <{$to}>\r\nSubject: {$subject}\r\n" .
               "Date: " . date('r') . "\r\nContent-Type: text/plain; charset=utf-8\r\nMIME-Version: 1.0";

    $smtp_protocol = ($encryption === 'ssl') ? 'ssl://' : '';
    $smtp = @fsockopen($smtp_protocol . $host, $port, $errno, $errstr, 15);

    if (!$smtp) {
        error_log("SMTP Connection Failed: [$errno] $errstr");
        return;
    }
    
    $log = []; // For debugging if needed
    function send_smtp_command($smtp, $cmd, &$log_array) {
        fputs($smtp, $cmd . "\r\n");
        return fgets($smtp, 512);
    }

    fgets($smtp, 512);
    send_smtp_command($smtp, "HELO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), $log);
    if ($encryption === 'tls') {
        send_smtp_command($smtp, "STARTTLS", $log);
        stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
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
    fputs($smtp, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
    fgets($smtp, 512);
    send_smtp_command($smtp, "QUIT", $log);
    fclose($smtp);
}


// --- Discord Webhook Notification Function ---
function sendDiscordNotification($monitor, $status, $webhookUrl) {
    if (empty($webhookUrl)) {
        error_log("Discord notification not sent: Webhook URL is not configured.");
        return;
    }
    $statusUpper = strtoupper($status);
    $color = ($statusUpper === 'UP') ? 3066993 : 15158332; // Green : Red

    $embed = [
        'title' => "FamPing Alert: {$monitor['name']} is {$statusUpper}",
        'description' => "The monitor for `{$monitor['ip_address']}` has changed state.",
        'color' => $color,
        'timestamp' => date('c'),
        'footer' => ['text' => 'FamPing Monitor']
    ];

    $payload = json_encode(['username' => 'FamPing Alert', 'embeds' => [$embed]]);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-type: application/json'],
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Discord Webhook Error: ' . curl_error($ch));
    }
    curl_close($ch);
}
?>
