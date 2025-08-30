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
        // MODIFIED: The database is now in the /data volume inside the container.
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
function pingHost($host) {
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $command = $isWindows ? "ping -n 1 -w 1000 " . escapeshellarg($host) : "ping -c 1 -W 1 " . escapeshellarg($host);
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
    $subject = "FamPing Alert: {$monitor['name']} is {$status}";
    $body = "Alert from FamPing:\r\n\r\n- Monitor: {$monitor['name']}\r\n- Host: {$monitor['ip_address']}\r\n- Status: {$status}\r\n- Time: " . date('Y-m-d H:i:s');
    $headers = "From: \"FamPing Monitor\" <{$from_email}>\r\nTo: <{$to}>\r\nSubject: {$subject}\r\n";
    $smtp_protocol = ($encryption === 'ssl') ? 'ssl://' : '';
    $smtp = @fsockopen($smtp_protocol . $host, $port, $errno, $errstr, 15);
    if (!$smtp) {
        error_log("SMTP Connection Failed: [$errno] $errstr");
        return;
    }
    function send_smtp_command($smtp, $cmd) { fputs($smtp, $cmd . "\r\n"); return fgets($smtp, 512); }
    fgets($smtp, 512);
    send_smtp_command($smtp, "HELO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($encryption === 'tls') {
        send_smtp_command($smtp, "STARTTLS");
        stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        send_smtp_command($smtp, "HELO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }
    if (!empty($user) && !empty($pass)) {
        send_smtp_command($smtp, "AUTH LOGIN");
        send_smtp_command($smtp, base64_encode($user));
        send_smtp_command($smtp, base64_encode($pass));
    }
    send_smtp_command($smtp, "MAIL FROM: <{$from_email}>");
    send_smtp_command($smtp, "RCPT TO: <{$to}>");
    send_smtp_command($smtp, "DATA");
    fputs($smtp, $headers . "\r\n" . $body . "\r\n.\r\n");
    fgets($smtp, 512);
    send_smtp_command($smtp, "QUIT");
    fclose($smtp);
}

// --- Discord Webhook Notification Function ---
function sendDiscordNotification($monitor, $status, $webhookUrl) {
    if (empty($webhookUrl)) return;
    $statusUpper = strtoupper($status);
    $color = ($statusUpper === 'UP') ? 3066993 : 15158332;
    $embed = ['title' => "FamPing Alert: {$monitor['name']} is {$statusUpper}", 'description' => "The monitor for `{$monitor['ip_address']}` has changed state.", 'color' => $color, 'timestamp' => date('c'), 'footer' => ['text' => 'FamPing']];
    $payload = json_encode(['username' => 'FamPing Alert', 'embeds' => [$embed]]);
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch);
    curl_close($ch);
}
?>
