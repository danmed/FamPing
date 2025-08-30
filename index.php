<?php
/*
================================================================================
File: index.php
Description: Main dashboard to view monitor statuses and manage monitors.
================================================================================
*/

require_once 'config.php';
$db = getDbConnection();

$error_message = null;
$success_message = null;
$edit_monitor = null;
$all_monitors = [];
$monitors_tree = [];
$settings = [];
$proxmox_servers = [];
$edit_proxmox_server = null;

// --- Fetch Settings & Proxmox Servers ---
try {
    $settings = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $proxmox_servers = $db->query("SELECT * FROM proxmox_servers ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $error_message = "Could not load settings or servers. Please run setup.php.";
}

// --- Proxmox API Function ---
function proxmoxApiRequest($server, $endpoint) {
    $url = "https://{$server['hostname']}:{$server['port']}/api2/json{$endpoint}";
    $token = "PVEAPIToken={$server['username']}={$server['api_token']}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $token"]);
    // Allow insecure connections if user unchecked "Verify SSL"
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $server['verify_ssl'] == 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $server['verify_ssl'] == 1);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode Proxmox API response.');
    }
    return $data['data'] ?? null;
}

// --- Proxmox Sync Function ---
function syncProxmoxGuests($proxmox_server_id, $db) {
    // --- Check for cURL extension first ---
    if (!function_exists('curl_init')) {
        throw new Exception("The PHP cURL extension is required for Proxmox integration, but it is not installed or enabled on your web server.");
    }

    $stmt = $db->prepare("SELECT * FROM proxmox_servers WHERE id = ?");
    $stmt->execute([$proxmox_server_id]);
    $server = $stmt->fetch();
    if (!$server) {
        throw new Exception("Proxmox server config not found.");
    }

    // 1. Get or create the anchor monitor for the PVE host itself
    $anchor_monitor_id = $server['anchor_monitor_id'];
    if ($anchor_monitor_id) {
        $stmt = $db->prepare("SELECT id FROM monitors WHERE id = ?");
        $stmt->execute([$anchor_monitor_id]);
        if (!$stmt->fetch()) {
            $anchor_monitor_id = null; // Stale ID, needs recreation
        }
    }
    if (!$anchor_monitor_id) {
        $monitor_name = "PVE Host - {$server['name']}";
        $db->prepare("INSERT INTO monitors (name, ip_address) VALUES (?, ?)")
           ->execute([$monitor_name, $server['hostname']]);
        $anchor_monitor_id = $db->lastInsertId();
        $db->prepare("UPDATE proxmox_servers SET anchor_monitor_id = ? WHERE id = ?")
           ->execute([$anchor_monitor_id, $server['id']]);
    }

    // 2. Fetch all nodes, then guests from each node, adding type and node info
    $nodes = proxmoxApiRequest($server, '/nodes');
    if ($nodes === null) throw new Exception("Could not fetch nodes from Proxmox.");
    
    $all_guests = [];
    foreach ($nodes as $node) {
        $node_name = $node['node'];
        $qemu_guests = proxmoxApiRequest($server, "/nodes/{$node_name}/qemu");
        if ($qemu_guests) {
            foreach($qemu_guests as &$guest) {
                $guest['type'] = 'qemu';
                $guest['node'] = $node_name;
            }
            unset($guest);
            $all_guests = array_merge($all_guests, $qemu_guests);
        }
        
        $lxc_guests = proxmoxApiRequest($server, "/nodes/{$node_name}/lxc");
        if ($lxc_guests) {
            foreach($lxc_guests as &$guest) {
                $guest['type'] = 'lxc';
                $guest['node'] = $node_name;
            }
            unset($guest);
            $all_guests = array_merge($all_guests, $lxc_guests);
        }
    }
    
    // 3. Get existing child monitors to avoid duplicates
    $stmt = $db->prepare("SELECT name FROM monitors WHERE parent_id = ?");
    $stmt->execute([$anchor_monitor_id]);
    $existing_children_names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 4. Loop through guests and add them if they don't exist
    $added_count = 0;
    $insert_stmt = $db->prepare("INSERT INTO monitors (name, ip_address, parent_id) VALUES (?, ?, ?)");
    foreach($all_guests as $guest) {
        $guest_name = $guest['name'] ?? 'unknown';
        $vmid = $guest['vmid'] ?? '??';
        $monitor_name = "PVE Guest - {$guest_name} ({$vmid})";
        
        if (!in_array($monitor_name, $existing_children_names)) {
            // --- Try to get guest IP address using a waterfall of methods ---
            $ip_address_to_use = $guest_name; // Default fallback to hostname
            $is_running = isset($guest['status']) && $guest['status'] === 'running';
            $ip_found = false;

            if ($is_running) {
                // Method 1: Direct Network State API (most reliable, works for DHCP)
                try {
                    $endpoint = "/nodes/{$guest['node']}/{$guest['type']}/{$guest['vmid']}/network";
                    $net_data = proxmoxApiRequest($server, $endpoint);
                    if (is_array($net_data)) {
                        foreach ($net_data as $interface) {
                            if (isset($interface['ip-addresses']) && is_array($interface['ip-addresses'])) {
                                foreach ($interface['ip-addresses'] as $ip_info) {
                                    if (isset($ip_info['ip-address-type'], $ip_info['ip-address']) &&
                                        $ip_info['ip-address-type'] === 'ipv4' &&
                                        $ip_info['ip-address'] !== '127.0.0.1' &&
                                        strpos($ip_info['ip-address'], '169.254.') !== 0) {
                                        $ip_address_to_use = $ip_info['ip-address'];
                                        $ip_found = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) { /* Silently ignore */ }

                // Method 2: QEMU Guest Agent (fallback)
                if (!$ip_found) {
                    try {
                        $endpoint = "/nodes/{$guest['node']}/{$guest['type']}/{$guest['vmid']}/agent/network-get-interfaces";
                        $net_data = proxmoxApiRequest($server, $endpoint);
                        if (isset($net_data['result']) && is_array($net_data['result'])) {
                            foreach ($net_data['result'] as $interface) {
                                if (isset($interface['ip-addresses']) && is_array($interface['ip-addresses'])) {
                                    foreach ($interface['ip-addresses'] as $ip_info) {
                                        if (isset($ip_info['ip-address-type'], $ip_info['ip-address']) &&
                                            $ip_info['ip-address-type'] === 'ipv4' &&
                                            $ip_info['ip-address'] !== '127.0.0.1' &&
                                            strpos($ip_info['ip-address'], '169.254.') !== 0) {
                                            $ip_address_to_use = $ip_info['ip-address'];
                                            $ip_found = true;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) { /* Silently ignore */ }
                }

                // Method 3: LXC Config file parsing (final fallback for LXC)
                if (!$ip_found && $guest['type'] === 'lxc') {
                    try {
                        $endpoint = "/nodes/{$guest['node']}/lxc/{$guest['vmid']}/config";
                        $config_data = proxmoxApiRequest($server, $endpoint);
                        if (is_array($config_data)) {
                             foreach ($config_data as $key => $value) {
                                if (strpos($key, 'net') === 0 && is_string($value)) {
                                    if (preg_match('/ip=([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/', $value, $matches)) {
                                        $ip = $matches[1];
                                        if ($ip !== '0.0.0.0' && $ip !== '127.0.0.1' && strpos($ip, '169.254.') !== 0) {
                                            $ip_address_to_use = $ip;
                                            $ip_found = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) { /* Silently ignore */ }
                }
            }
            // --- END of IP Address Logic ---

            $insert_stmt->execute([$monitor_name, $ip_address_to_use, $anchor_monitor_id]);
            $added_count++;
        }
    }

    return $added_count;
}


// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- Monitor Forms ---
        if (isset($_POST['save_monitor'])) {
            $name = trim($_POST['name']);
            $ip = trim($_POST['ip_address']);
            $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
            $id = $_POST['id'] ?? null;

            if ($id) {
                $stmt = $db->prepare("UPDATE monitors SET name = ?, ip_address = ?, parent_id = ? WHERE id = ?");
                $stmt->execute([$name, $ip, $parentId, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO monitors (name, ip_address, parent_id) VALUES (?, ?, ?)");
                $stmt->execute([$name, $ip, $parentId]);
            }
        }
        if (isset($_POST['delete_monitor'])) {
            $stmt = $db->prepare("DELETE FROM monitors WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success_message = "Monitor deleted successfully.";
        }
        // --- Handle Manual Check ---
        if (isset($_POST['check_now'])) {
            $monitor_id = $_POST['id'];
            $stmt = $db->prepare("SELECT * FROM monitors WHERE id = ?");
            $stmt->execute([$monitor_id]);
            $monitor = $stmt->fetch();
    
            if ($monitor) {
                $is_up = pingHost($monitor['ip_address']);
                $status_string = $is_up ? 'up' : 'down';
    
                // Update monitor status
                $update_stmt = $db->prepare("UPDATE monitors SET last_status = ?, last_check = datetime('now', 'localtime') WHERE id = ?");
                $update_stmt->execute([$status_string, $monitor_id]);
    
                // Add to history
                $hist_stmt = $db->prepare("INSERT INTO ping_history (monitor_id, status, check_time) VALUES (?, ?, datetime('now', 'localtime'))");
                $hist_stmt->execute([$monitor_id, $status_string]);
    
                $success_message = "Manual check complete for '".htmlspecialchars($monitor['name'])."'. Status: " . strtoupper($status_string);
            } else {
                $error_message = "Could not find monitor to check.";
            }
        }
        
        // --- Settings Form ---
        if (isset($_POST['save_settings'])) {
            $enable_email = isset($_POST['enable_email']) ? '1' : '0';
            $email_to = $_POST['email_to'] ?? '';
            $smtp_host = $_POST['smtp_host'] ?? '';
            $smtp_port = $_POST['smtp_port'] ?? '587';
            $smtp_user = $_POST['smtp_user'] ?? '';
            $smtp_pass = $_POST['smtp_pass'] ?? '';
            $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';

            $enable_discord = isset($_POST['enable_discord']) ? '1' : '0';
            $webhook_url = $_POST['discord_webhook_url'] ?? '';

            $check_interval = $_POST['check_interval_seconds'] ?? '300';

            $db->prepare("UPDATE settings SET value = ? WHERE key = 'enable_email'")->execute([$enable_email]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'email_to'")->execute([$email_to]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'smtp_host'")->execute([$smtp_host]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'smtp_port'")->execute([$smtp_port]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'smtp_user'")->execute([$smtp_user]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'smtp_pass'")->execute([$smtp_pass]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'smtp_encryption'")->execute([$smtp_encryption]);

            $db->prepare("UPDATE settings SET value = ? WHERE key = 'enable_discord'")->execute([$enable_discord]);
            $db->prepare("UPDATE settings SET value = ? WHERE key = 'discord_webhook_url'")->execute([$webhook_url]);

            $db->prepare("UPDATE settings SET value = ? WHERE key = 'check_interval_seconds'")->execute([$check_interval]);
            
            $success_message = "Settings saved successfully! Container must be restarted for check interval changes to take effect.";
            // Re-fetch settings to show updated values
            $settings = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // --- Proxmox Forms ---
        if (isset($_POST['save_proxmox'])) {
            $id = $_POST['proxmox_id'] ?? null;
            $name = trim($_POST['proxmox_name']);
            $hostname = trim($_POST['proxmox_hostname']);
            $port = filter_var($_POST['proxmox_port'], FILTER_VALIDATE_INT, ['options' => ['default' => 8006]]);
            $username = trim($_POST['proxmox_username']);
            $token = trim($_POST['proxmox_api_token']);
            $verify_ssl = isset($_POST['proxmox_verify_ssl']) ? 1 : 0;
            
            if ($id) {
                $stmt = $db->prepare("UPDATE proxmox_servers SET name=?, hostname=?, port=?, username=?, api_token=?, verify_ssl=? WHERE id=?");
                $stmt->execute([$name, $hostname, $port, $username, $token, $verify_ssl, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO proxmox_servers (name, hostname, port, username, api_token, verify_ssl) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $hostname, $port, $username, $token, $verify_ssl]);
            }
            $success_message = "Proxmox server configuration saved.";
        }
        if (isset($_POST['delete_proxmox'])) {
            $db->prepare("DELETE FROM proxmox_servers WHERE id = ?")->execute([$_POST['proxmox_id']]);
            $success_message = "Proxmox server configuration deleted.";
        }
        if (isset($_POST['sync_proxmox'])) {
            $added_count = syncProxmoxGuests($_POST['proxmox_id'], $db);
            $success_message = "Proxmox sync complete. Added {$added_count} new guests as monitors.";
        }

    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
    
    // Refresh page after a successful action to show changes
    if (($success_message || isset($_POST['save_monitor']) || isset($_POST['check_now'])) && !$error_message) {
        header("Location: index.php");
        exit();
    }
}

// --- Handle Edit Requests ---
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM monitors WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_monitor = $stmt->fetch();
}
if (isset($_GET['edit_proxmox'])) {
    $stmt = $db->prepare("SELECT * FROM proxmox_servers WHERE id = ?");
    $stmt->execute([$_GET['edit_proxmox']]);
    $edit_proxmox_server = $stmt->fetch();
}


// --- Fetch All Monitors for Display ---
try {
    $monitors_raw = $db->query("SELECT * FROM monitors")->fetchAll();
    foreach($monitors_raw as $m) {
        $all_monitors[$m['id']] = $m;
    }

    foreach ($all_monitors as $id => &$monitor) {
        if (empty($monitor['parent_id'])) {
            $monitors_tree[$id] = &$monitor;
        } else {
            if (isset($all_monitors[$monitor['parent_id']])) {
                $all_monitors[$monitor['parent_id']]['children'][$id] = &$monitor;
            }
        }
    }
} catch (PDOException $e) {
    $error_message = $error_message ?: "Database Error: " . $e->getMessage() . ". Please make sure you have run the <strong>setup.php</strong> script.";
}

// --- Fetch recent history for all monitors ---
$history_by_monitor = [];
try {
    if(!empty($all_monitors)) {
        $history_query = "
            SELECT monitor_id, status FROM (
                SELECT monitor_id, status, ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY check_time DESC) as rn
                FROM ping_history
            ) WHERE rn <= 30
        ";
        foreach ($db->query($history_query) as $record) {
            $history_by_monitor[$record['monitor_id']][] = $record['status'];
        }
    }
} catch (PDOException $e) { /* Silently fail on old SQLite */ }

// --- Render function ---
function renderMonitors(array $monitors, array $history_data) {
    foreach ($monitors as $monitor) {
        $has_children = !empty($monitor['children']);
        $status_color = 'bg-gray-400';
        if ($monitor['last_status'] === 'up') $status_color = 'bg-green-500';
        if ($monitor['last_status'] === 'down') $status_color = 'bg-red-500';
        $monitor_id = $monitor['id'];

        // Main monitor container
        echo "<div>"; 
        
        // Main monitor card
        echo "<div class='p-4 border rounded-lg shadow-sm bg-white'>";
        echo "<div class='flex flex-wrap items-center justify-between gap-y-2'>";
        
        // Left side: status, name, ip
        echo "<div class='flex items-center space-x-2'>";
        
        // Add collapse button with SVG arrow if it has children
        if ($has_children) {
            echo "<button @click='toggle({$monitor_id})' class='flex items-center justify-center w-6 h-6 rounded-md hover:bg-gray-100 focus:outline-none' title='Toggle children'>";
            echo "<svg class='w-4 h-4 text-gray-500 transition-transform duration-200' :class='{ \"rotate-90\": isExpanded({$monitor_id}) }' fill='none' viewBox='0 0 24 24' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 5l7 7-7 7' /></svg>";
            echo "</button>";
        } else {
            // Add a spacer for alignment if it has no children
            echo "<div class='w-6'></div>";
        }
        
        echo "<span id='monitor-status-{$monitor_id}' class='w-4 h-4 rounded-full {$status_color}'></span>"; // Added ID for JS targeting
        echo "<div><p class='font-bold text-lg'>".htmlspecialchars($monitor['name'])."</p><p class='text-sm text-gray-500'>".htmlspecialchars($monitor['ip_address'])."</p></div>";
        echo "</div>";

        // Right side: last check and actions
        echo "<div class='text-right'>";
        echo "<p id='monitor-last-check-{$monitor_id}' class='text-sm text-gray-600'>Last check: " . ($monitor['last_check'] ? date('Y-m-d H:i:s', strtotime($monitor['last_check'])) : 'N/A') . "</p>"; // Added ID for JS targeting
        echo "<div class='flex items-center space-x-3 mt-2'>";
        echo "<form method='POST' class='inline'><input type='hidden' name='id' value='{$monitor['id']}'><button type='submit' name='check_now' class='text-sm text-indigo-600 hover:underline'>Check</button></form>";
        echo "<a href='history.php?id={$monitor['id']}' class='text-sm text-green-600 hover:underline'>History</a>";
        echo "<a href='?edit={$monitor['id']}' class='text-sm text-blue-500 hover:underline'>Edit</a>";
        echo "<form method='POST' onsubmit='return confirm(\"Are you sure?\");'><input type='hidden' name='id' value='{$monitor['id']}'><button type='submit' name='delete_monitor' class='text-sm text-red-500 hover:underline'>Delete</button></form>";
        echo "</div>";
        echo "</div>";
        echo "</div>";

        // History bar
        $monitor_history = $history_data[$monitor['id']] ?? [];
        echo "<div id='monitor-history-{$monitor_id}' class='mt-3 flex items-center space-x-px' title='Last 30 checks. Most recent is on the right.'>"; // Added ID for JS targeting
        if (!empty($monitor_history)) {
            $display_history = array_reverse($monitor_history);
            for ($i = 0; $i < (30 - count($display_history)); $i++) echo "<div class='w-2 h-5 rounded-sm bg-gray-200'></div>";
            foreach ($display_history as $status) echo "<div class='w-2 h-5 rounded-sm ".($status === 'up' ? 'bg-green-500' : 'bg-red-500')."'></div>";
        }
        echo "</div>";
        echo "</div>"; // Close monitor card div

        // Collapsible children container
        if ($has_children) {
            echo "<div x-show='isExpanded({$monitor_id})' x-transition x-cloak class='ml-8 mt-2 space-y-2 border-l-2 pl-4'>";
            renderMonitors($monitor['children'], $history_data);
            echo "</div>";
        }

        echo "</div>"; // Close main monitor container div
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPing Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Inter', sans-serif; } [x-cloak] { display: none; }</style>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-800">
<div class="container mx-auto p-4 md:p-8">
    <header class="mb-8">
        <h1 class="text-4xl font-bold text-gray-900">PHPing</h1>
        <p class="text-gray-600 mt-1">A simple status page for your hosts and services.</p>
    </header>

    <?php if ($error_message): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
        <strong class="font-bold">Application Error!</strong> <span class="block sm:inline"><?= $error_message ?></span>
    </div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
        <strong class="font-bold">Success!</strong> <span class="block sm:inline"><?= $success_message ?></span>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-4" x-data="monitorState()" x-init="init()">
            <h2 class="text-2xl font-semibold border-b pb-2 mb-4">Monitors</h2>
            <?php if (empty($monitors_tree) && !$error_message): ?>
                <p class="text-gray-500">No monitors configured. Add one using the form.</p>
            <?php else: ?>
                <?php renderMonitors($monitors_tree, $history_by_monitor); ?>
            <?php endif; ?>
        </div>
        <div x-data="{ tab: '<?= $edit_monitor ? 'monitor' : ($edit_proxmox_server ? 'proxmox' : 'monitor') ?>' }" class="bg-white p-6 rounded-lg shadow-md">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button @click="tab = 'monitor'" :class="{ 'border-indigo-500 text-indigo-600': tab === 'monitor', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': tab !== 'monitor' }" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        <?= $edit_monitor ? 'Edit Monitor' : 'Add Monitor' ?>
                    </button>
                    <button @click="tab = 'settings'" :class="{ 'border-indigo-500 text-indigo-600': tab === 'settings', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': tab !== 'settings' }" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Settings
                    </button>
                    <button @click="tab = 'proxmox'" :class="{ 'border-indigo-500 text-indigo-600': tab === 'proxmox', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': tab !== 'proxmox' }" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        Proxmox
                    </button>
                </nav>
            </div>

            <!-- Monitor Form -->
            <div x-show="tab === 'monitor'" x-cloak class="mt-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900"><?= $edit_monitor ? 'Edit Monitor' : 'Add New Monitor' ?></h3>
                <form method="POST" class="space-y-4 mt-4">
                    <input type="hidden" name="id" value="<?= $edit_monitor['id'] ?? '' ?>">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Monitor Name</label>
                        <input type="text" id="name" name="name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g., Main Web Server" value="<?= htmlspecialchars($edit_monitor['name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="ip_address" class="block text-sm font-medium text-gray-700">IP Address or Hostname</label>
                        <input type="text" id="ip_address" name="ip_address" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g., 8.8.8.8" value="<?= htmlspecialchars($edit_monitor['ip_address'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="parent_id" class="block text-sm font-medium text-gray-700">Parent Monitor (Optional)</label>
                        <select id="parent_id" name="parent_id" class="mt-1 block w-full pl-3 pr-10 py-2 border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 rounded-md">
                            <option value="">None</option>
                            <?php
                            $current_id = $edit_monitor['id'] ?? 0;
                            foreach ($all_monitors as $monitor) {
                                if ($monitor['id'] === $current_id) continue;
                                $selected = isset($edit_monitor['parent_id']) && $edit_monitor['parent_id'] == $monitor['id'] ? 'selected' : '';
                                echo "<option value='{$monitor['id']}' {$selected}>" . htmlspecialchars($monitor['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button type="submit" name="save_monitor" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none">
                            <?= $edit_monitor ? 'Update Monitor' : 'Save Monitor' ?>
                        </button>
                        <?php if ($edit_monitor): ?>
                        <a href="index.php" class="w-full text-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Settings Form -->
            <div x-show="tab === 'settings'" x-cloak class="mt-6">
                 <form method="POST" class="space-y-6">
                    <div class="space-y-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">General Settings</h3>
                         <div>
                            <label for="check_interval_seconds" class="block text-sm font-medium text-gray-700">Check Interval (seconds)</label>
                            <input type="number" name="check_interval_seconds" id="check_interval_seconds" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="300" value="<?= htmlspecialchars($settings['check_interval_seconds'] ?? '300') ?>">
                            <p class="mt-2 text-sm text-gray-500">How often the background worker should check monitors. Requires a container restart to take effect.</p>
                        </div>
                    </div>
                    <hr>
                    <div class="space-y-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Email Notifications</h3>
                        <div class="relative flex items-start">
                            <div class="flex items-center h-5"><input id="enable_email" name="enable_email" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?= ($settings['enable_email'] ?? '0') === '1' ? 'checked' : '' ?>></div>
                            <div class="ml-3 text-sm"><label for="enable_email" class="font-medium text-gray-700">Enable Email Notifications</label></div>
                        </div>
                        <div>
                            <label for="email_to" class="block text-sm font-medium text-gray-700">Recipient Email</label>
                            <input type="email" name="email_to" id="email_to" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="alerts@example.com" value="<?= htmlspecialchars($settings['email_to'] ?? '') ?>">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="smtp_host" class="block text-sm font-medium text-gray-700">SMTP Host</label>
                                <input type="text" name="smtp_host" id="smtp_host" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="smtp.example.com" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="smtp_port" class="block text-sm font-medium text-gray-700">SMTP Port</label>
                                <input type="number" name="smtp_port" id="smtp_port" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="587" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="smtp_user" class="block text-sm font-medium text-gray-700">SMTP Username</label>
                                <input type="text" name="smtp_user" id="smtp_user" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="user@example.com" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="smtp_pass" class="block text-sm font-medium text-gray-700">SMTP Password</label>
                                <input type="password" name="smtp_pass" id="smtp_pass" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="••••••••" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
                            </div>
                        </div>
                        <div>
                            <label for="smtp_encryption" class="block text-sm font-medium text-gray-700">Encryption</label>
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full pl-3 pr-10 py-2 border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                                <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <div class="space-y-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Discord Notifications</h3>
                        <div class="relative flex items-start">
                             <div class="flex items-center h-5"><input id="enable_discord" name="enable_discord" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?= ($settings['enable_discord'] ?? '0') === '1' ? 'checked' : '' ?>></div>
                            <div class="ml-3 text-sm"><label for="enable_discord" class="font-medium text-gray-700">Enable Discord Notifications</label></div>
                        </div>
                        <div>
                            <label for="discord_webhook_url" class="block text-sm font-medium text-gray-700">Discord Webhook URL</label>
                            <input type="url" name="discord_webhook_url" id="discord_webhook_url" class="mt-1 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" placeholder="https://discord.com/api/webhooks/..." value="<?= htmlspecialchars($settings['discord_webhook_url'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div>
                        <button type="submit" name="save_settings" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none">
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Proxmox Form & List -->
            <div x-show="tab === 'proxmox'" x-cloak class="mt-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900"><?= $edit_proxmox_server ? 'Edit' : 'Add' ?> Proxmox Server</h3>
                <form method="POST" class="space-y-4 mt-4">
                    <input type="hidden" name="proxmox_id" value="<?= $edit_proxmox_server['id'] ?? '' ?>">
                    <div>
                        <label for="proxmox_name" class="block text-sm font-medium text-gray-700">Friendly Name</label>
                        <input type="text" name="proxmox_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" placeholder="e.g., Home Lab PVE" value="<?= htmlspecialchars($edit_proxmox_server['name'] ?? '') ?>">
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label for="proxmox_hostname" class="block text-sm font-medium text-gray-700">Hostname / IP</label>
                            <input type="text" name="proxmox_hostname" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" placeholder="pve.example.com" value="<?= htmlspecialchars($edit_proxmox_server['hostname'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="proxmox_port" class="block text-sm font-medium text-gray-700">Port</label>
                            <input type="number" name="proxmox_port" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" value="<?= htmlspecialchars($edit_proxmox_server['port'] ?? '8006') ?>">
                        </div>
                    </div>
                    <div>
                        <label for="proxmox_username" class="block text-sm font-medium text-gray-700">Username (e.g. root@pam)</label>
                        <input type="text" name="proxmox_username" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" placeholder="user@realm" value="<?= htmlspecialchars($edit_proxmox_server['username'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="proxmox_api_token" class="block text-sm font-medium text-gray-700">API Token</label>
                        <input type="password" name="proxmox_api_token" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" value="<?= htmlspecialchars($edit_proxmox_server['api_token'] ?? '') ?>">
                    </div>
                    <div class="relative flex items-start">
                        <div class="flex items-center h-5">
                            <input id="proxmox_verify_ssl" name="proxmox_verify_ssl" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?= !isset($edit_proxmox_server) || ($edit_proxmox_server['verify_ssl'] ?? '1') == 1 ? 'checked' : '' ?>>
                        </div>
                        <div class="ml-3 text-sm">
                            <label for="proxmox_verify_ssl" class="font-medium text-gray-700">Verify SSL Certificate</label>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button type="submit" name="save_proxmox" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                           <?= $edit_proxmox_server ? 'Update Server' : 'Save Server' ?>
                        </button>
                        <?php if ($edit_proxmox_server): ?>
                            <a href="index.php" class="w-full text-center py-2 px-4 border rounded-md shadow-sm text-sm font-medium">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>

                <hr class="my-6">
                
                <h3 class="text-lg font-medium leading-6 text-gray-900">Configured Servers</h3>
                <div class="space-y-2 mt-4">
                    <?php if (empty($proxmox_servers)): ?>
                        <p class="text-sm text-gray-500">No Proxmox servers configured yet.</p>
                    <?php else: foreach ($proxmox_servers as $server): ?>
                        <div class="p-2 border rounded-md flex justify-between items-center">
                           <div>
                                <p class="font-semibold"><?= htmlspecialchars($server['name']) ?></p>
                                <p class="text-xs text-gray-600"><?= htmlspecialchars($server['hostname']) ?></p>
                           </div>
                           <div class="flex items-center space-x-2">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="proxmox_id" value="<?= $server['id'] ?>">
                                    <button type="submit" name="sync_proxmox" class="text-sm text-green-600 hover:underline" title="Sync VMs/LXCs">Sync</button>
                                </form>
                                <a href="?edit_proxmox=<?= $server['id'] ?>" class="text-sm text-blue-500 hover:underline">Edit</a>
                                <form method="POST" onsubmit="return confirm('Delete this server config? This does NOT delete the monitors.');" class="inline">
                                    <input type="hidden" name="proxmox_id" value="<?= $server['id'] ?>">
                                    <button type="submit" name="delete_proxmox" class="text-sm text-red-500 hover:underline">Delete</button>
                                </form>
                           </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

            </div>
        </div>
    </div>
    <footer class="mt-12 pt-4 border-t text-center text-sm text-gray-500">
        <p>PHPing - A PHP IP Monitor App</p>
    </footer>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // --- AJAX Auto-Refresh ---
        const updateInterval = 30000; // 30 seconds
        const updateMonitorStatuses = () => {
            fetch('api.php')
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(json => {
                    if (json.success && json.data) {
                        const monitorData = json.data;
                        for (const monitorId in monitorData) {
                            const data = monitorData[monitorId];
                            
                            // Update Status Dot
                            const statusEl = document.getElementById(`monitor-status-${monitorId}`);
                            if (statusEl) {
                                statusEl.className = 'w-4 h-4 rounded-full '; // Reset classes
                                if (data.status === 'up') statusEl.classList.add('bg-green-500');
                                else if (data.status === 'down') statusEl.classList.add('bg-red-500');
                                else statusEl.classList.add('bg-gray-400');
                            }

                            // Update Last Check Time
                            const lastCheckEl = document.getElementById(`monitor-last-check-${monitorId}`);
                            if (lastCheckEl) lastCheckEl.textContent = 'Last check: ' + data.last_check;

                            // Update History Bar
                            const historyEl = document.getElementById(`monitor-history-${monitorId}`);
                            if (historyEl) {
                                let historyHtml = '';
                                const historyCount = data.history.length;
                                const isDark = document.documentElement.classList.contains('dark');
                                const placeholderColor = isDark ? 'bg-gray-600' : 'bg-gray-200';
                                for (let i = 0; i < (30 - historyCount); i++) {
                                    historyHtml += `<div class='w-2 h-5 rounded-sm ${placeholderColor}'></div>`;
                                }
                                data.history.forEach(status => {
                                    const colorClass = status === 'up' ? 'bg-green-500' : 'bg-red-500';
                                    historyHtml += `<div class='w-2 h-5 rounded-sm ${colorClass}'></div>`;
                                });
                                historyEl.innerHTML = historyHtml;
                            }
                        }
                    }
                })
                .catch(error => console.error('Failed to fetch monitor statuses:', error));
        };
        setInterval(updateMonitorStatuses, updateInterval);
    });
</script>
</body>
</html>

