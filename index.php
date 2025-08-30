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
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => ["Authorization: $token"],
        CURLOPT_SSL_VERIFYPEER => $server['verify_ssl'] == 1,
        CURLOPT_SSL_VERIFYHOST => $server['verify_ssl'] == 1
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception('cURL error: ' . curl_error($ch));
    curl_close($ch);
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Failed to decode Proxmox API response.');
    return $data['data'] ?? null;
}

// --- Proxmox Sync Function ---
function syncProxmoxGuests($proxmox_server_id, $db) {
    if (!function_exists('curl_init')) throw new Exception("The PHP cURL extension is not installed/enabled.");

    $stmt = $db->prepare("SELECT * FROM proxmox_servers WHERE id = ?");
    $stmt->execute([$proxmox_server_id]);
    $server = $stmt->fetch();
    if (!$server) throw new Exception("Proxmox server config not found.");

    $anchor_monitor_id = $server['anchor_monitor_id'];
    if ($anchor_monitor_id) {
        $stmt = $db->prepare("SELECT id FROM monitors WHERE id = ?");
        $stmt->execute([$anchor_monitor_id]);
        if (!$stmt->fetch()) $anchor_monitor_id = null;
    }
    if (!$anchor_monitor_id) {
        $monitor_name = "PVE Host - {$server['name']}";
        $db->prepare("INSERT INTO monitors (name, ip_address) VALUES (?, ?)")->execute([$monitor_name, $server['hostname']]);
        $anchor_monitor_id = $db->lastInsertId();
        $db->prepare("UPDATE proxmox_servers SET anchor_monitor_id = ? WHERE id = ?")->execute([$anchor_monitor_id, $server['id']]);
    }

    $nodes = proxmoxApiRequest($server, '/nodes');
    if ($nodes === null) throw new Exception("Could not fetch nodes from Proxmox.");
    
    $all_guests = [];
    foreach ($nodes as $node) {
        $node_name = $node['node'];
        foreach (['qemu', 'lxc'] as $type) {
            $guests = proxmoxApiRequest($server, "/nodes/{$node_name}/{$type}");
            if ($guests) {
                foreach ($guests as &$guest) { $guest['type'] = $type; $guest['node'] = $node_name; }
                $all_guests = array_merge($all_guests, $guests);
            }
        }
    }
    
    $stmt = $db->prepare("SELECT name FROM monitors WHERE parent_id = ?");
    $stmt->execute([$anchor_monitor_id]);
    $existing_children_names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $added_count = 0;
    $insert_stmt = $db->prepare("INSERT INTO monitors (name, ip_address, parent_id) VALUES (?, ?, ?)");
    foreach($all_guests as $guest) {
        $guest_name = $guest['name'] ?? 'unknown';
        $vmid = $guest['vmid'] ?? '??';
        $monitor_name = "PVE Guest - {$guest_name} ({$vmid})";
        
        if (!in_array($monitor_name, $existing_children_names)) {
            $ip_address_to_use = $guest_name;
            $is_running = isset($guest['status']) && $guest['status'] === 'running';
            $ip_found = false;

            if ($is_running) {
                try {
                    $endpoint = "/nodes/{$guest['node']}/{$guest['type']}/{$guest['vmid']}/agent/network-get-interfaces";
                    $net_data = proxmoxApiRequest($server, $endpoint);
                    if (isset($net_data['result'])) {
                        foreach ($net_data['result'] as $iface) {
                            if (isset($iface['ip-addresses'])) {
                                foreach ($iface['ip-addresses'] as $ip_info) {
                                    if (($ip_info['ip-address-type'] ?? '') === 'ipv4' && filter_var($ip_info['ip-address'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                        $ip_address_to_use = $ip_info['ip-address'];
                                        $ip_found = true; break 2;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {}

                if (!$ip_found && $guest['type'] === 'lxc') {
                    try {
                        $endpoint = "/nodes/{$guest['node']}/lxc/{$guest['vmid']}/config";
                        $config_data = proxmoxApiRequest($server, $endpoint);
                        if ($config_data) {
                             foreach ($config_data as $key => $value) {
                                if (strpos($key, 'net') === 0 && preg_match('/ip=([0-9\.]+)\//', $value, $matches)) {
                                    if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                        $ip_address_to_use = $matches[1]; break;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
            $insert_stmt->execute([$monitor_name, $ip_address_to_use, $anchor_monitor_id]);
            $added_count++;
        }
    }
    return $added_count;
}


// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_monitor'])) {
            $id = $_POST['id'] ?? null;
            $name = trim($_POST['name']);
            $ip = trim($_POST['ip_address']);
            $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
            if ($id) {
                $db->prepare("UPDATE monitors SET name = ?, ip_address = ?, parent_id = ? WHERE id = ?")->execute([$name, $ip, $parentId, $id]);
            } else {
                $db->prepare("INSERT INTO monitors (name, ip_address, parent_id) VALUES (?, ?, ?)")->execute([$name, $ip, $parentId]);
            }
        } elseif (isset($_POST['delete_monitor'])) {
            $db->prepare("DELETE FROM monitors WHERE id = ?")->execute([$_POST['id']]);
            $success_message = "Monitor deleted.";
        } elseif (isset($_POST['save_settings'])) {
            $settings_to_save = [
                'enable_email' => isset($_POST['enable_email']) ? '1' : '0',
                'email_to' => $_POST['email_to'] ?? '', 'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '587', 'smtp_user' => $_POST['smtp_user'] ?? '',
                'smtp_pass' => $_POST['smtp_pass'] ?? '', 'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'enable_discord' => isset($_POST['enable_discord']) ? '1' : '0',
                'discord_webhook_url' => $_POST['discord_webhook_url'] ?? ''
            ];
            $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = ?");
            foreach ($settings_to_save as $key => $value) $stmt->execute([$value, $key]);
            $success_message = "Settings saved.";
        } elseif (isset($_POST['save_proxmox'])) {
            $id = $_POST['proxmox_id'] ?? null;
            $params = [
                trim($_POST['proxmox_name']), trim($_POST['proxmox_hostname']),
                filter_var($_POST['proxmox_port'], FILTER_VALIDATE_INT, ['options' => ['default' => 8006]]),
                trim($_POST['proxmox_username']), trim($_POST['proxmox_api_token']),
                isset($_POST['proxmox_verify_ssl']) ? 1 : 0
            ];
            if ($id) {
                $params[] = $id;
                $db->prepare("UPDATE proxmox_servers SET name=?, hostname=?, port=?, username=?, api_token=?, verify_ssl=? WHERE id=?")->execute($params);
            } else {
                $db->prepare("INSERT INTO proxmox_servers (name, hostname, port, username, api_token, verify_ssl) VALUES (?, ?, ?, ?, ?, ?)")->execute($params);
            }
            $success_message = "Proxmox server saved.";
        } elseif (isset($_POST['delete_proxmox'])) {
            $db->prepare("DELETE FROM proxmox_servers WHERE id = ?")->execute([$_POST['proxmox_id']]);
            $success_message = "Proxmox server deleted.";
        } elseif (isset($_POST['sync_proxmox'])) {
            $added_count = syncProxmoxGuests($_POST['proxmox_id'], $db);
            $success_message = "Proxmox sync complete. Added {$added_count} new guests.";
        }
    } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }
    
    if (($success_message || isset($_POST['save_monitor'])) && !$error_message) {
        header("Location: index.php"); exit();
    }
}

// --- Handle Edit Requests ---
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM monitors WHERE id = ?"); $stmt->execute([$_GET['edit']]); $edit_monitor = $stmt->fetch();
}
if (isset($_GET['edit_proxmox'])) {
    $stmt = $db->prepare("SELECT * FROM proxmox_servers WHERE id = ?"); $stmt->execute([$_GET['edit_proxmox']]); $edit_proxmox_server = $stmt->fetch();
}

// --- Fetch Monitors for Display ---
try {
    $monitors_raw = $db->query("SELECT * FROM monitors")->fetchAll();
    foreach($monitors_raw as $m) $all_monitors[$m['id']] = $m;
    foreach ($all_monitors as $id => &$monitor) {
        if (empty($monitor['parent_id'])) $monitors_tree[$id] = &$monitor;
        elseif (isset($all_monitors[$monitor['parent_id']])) $all_monitors[$monitor['parent_id']]['children'][$id] = &$monitor;
    }
} catch (PDOException $e) { $error_message = $error_message ?: "Database Error: " . $e->getMessage() . ". Please run setup.php."; }

// --- Fetch recent history ---
$history_by_monitor = [];
try {
    if(!empty($all_monitors)) {
        $history_query = "SELECT monitor_id, status FROM (SELECT monitor_id, status, ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY check_time DESC) as rn FROM ping_history) WHERE rn <= 30";
        foreach ($db->query($history_query) as $record) $history_by_monitor[$record['monitor_id']][] = $record['status'];
    }
} catch (PDOException $e) {}

// --- Render function ---
function renderMonitors(array $monitors, array $history_data) {
    foreach ($monitors as $monitor) {
        $has_children = !empty($monitor['children']);
        $status_color = ['up' => 'bg-green-500', 'down' => 'bg-red-500', 'pending' => 'bg-gray-400'];
        $color = $status_color[$monitor['last_status']] ?? 'bg-gray-400';
        $monitor_id = $monitor['id'];

        echo "<div><div class='p-4 border rounded-lg shadow-sm bg-white'><div class='flex flex-wrap items-center justify-between gap-y-2'>";
        echo "<div class='flex items-center space-x-2'>";
        if ($has_children) {
            echo "<button @click='toggle({$monitor_id})' class='flex items-center justify-center w-6 h-6 rounded-md hover:bg-gray-100' title='Toggle children'><svg class='w-4 h-4 text-gray-500 transition-transform' :class='{ \"rotate-90\": isExpanded({$monitor_id}) }' fill='none' viewBox='0 0 24 24' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 5l7 7-7 7' /></svg></button>";
        } else { echo "<div class='w-6'></div>"; }
        echo "<span class='w-4 h-4 rounded-full {$color}'></span><div><p class='font-bold text-lg'>{$monitor['name']}</p><p class='text-sm text-gray-500'>{$monitor['ip_address']}</p></div></div>";
        echo "<div class='text-right'><p class='text-sm text-gray-600'>Last check: " . ($monitor['last_check'] ? date('Y-m-d H:i:s', strtotime($monitor['last_check'])) : 'N/A') . "</p>";
        echo "<div class='flex items-center space-x-3 mt-2'><a href='history.php?id={$monitor_id}' class='text-sm text-green-600 hover:underline'>History</a><a href='?edit={$monitor_id}' class='text-sm text-blue-500 hover:underline'>Edit</a>";
        echo "<form method='POST' onsubmit='return confirm(\"Are you sure?\");'><input type='hidden' name='id' value='{$monitor_id}'><button type='submit' name='delete_monitor' class='text-sm text-red-500 hover:underline'>Delete</button></form></div></div></div>";

        $history = $history_data[$monitor_id] ?? [];
        if (!empty($history)) {
            $display_history = array_reverse($history);
            echo "<div class='mt-3 flex items-center space-x-px' title='Last 30 checks'>";
            for ($i = 0; $i < (30 - count($display_history)); $i++) echo "<div class='w-2 h-5 rounded-sm bg-gray-200'></div>";
            foreach ($display_history as $status) echo "<div class='w-2 h-5 rounded-sm ".($status === 'up' ? 'bg-green-500' : 'bg-red-500')."'></div>";
            echo "</div>";
        }
        echo "</div>";
        if ($has_children) {
            echo "<div x-show='isExpanded({$monitor_id})' x-transition x-cloak class='ml-8 mt-2 space-y-2 border-l-2 pl-4'>";
            renderMonitors($monitor['children'], $history_data);
            echo "</div>";
        }
        echo "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FamPing Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Inter', sans-serif; } [x-cloak] { display: none; }</style>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        function monitorState() {
            return {
                expandedMonitors: [],
                init() {
                    try { this.expandedMonitors = JSON.parse(localStorage.getItem('expandedMonitors')) || []; } 
                    catch (e) { this.expandedMonitors = []; }
                },
                isExpanded(id) { return this.expandedMonitors.includes(id); },
                toggle(id) {
                    const index = this.expandedMonitors.indexOf(id);
                    if (index === -1) { this.expandedMonitors.push(id); } else { this.expandedMonitors.splice(index, 1); }
                    localStorage.setItem('expandedMonitors', JSON.stringify(this.expandedMonitors));
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-800">
<div class="container mx-auto p-4 md:p-8">
    <header class="mb-8"><h1 class="text-4xl font-bold">FamPing Dashboard</h1><p class="text-gray-600 mt-1">A simple status page for your hosts.</p></header>
    <?php if ($error_message): ?><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><strong>Error!</strong> <?= $error_message ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><strong>Success!</strong> <?= $success_message ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-4" x-data="monitorState()" x-init="init()">
            <h2 class="text-2xl font-semibold border-b pb-2 mb-4">Monitors</h2>
            <?php if (empty($monitors_tree) && !$error_message) echo '<p class="text-gray-500">No monitors configured.</p>'; else renderMonitors($monitors_tree, $history_by_monitor); ?>
        </div>
        <div class="bg-white p-6 rounded-lg shadow-md" x-data="{ tab: '<?= $edit_monitor ? 'monitor' : ($edit_proxmox_server ? 'proxmox' : 'monitor') ?>' }">
            <div class="border-b"><nav class="-mb-px flex space-x-8"><button @click="tab = 'monitor'" :class="{'border-indigo-500 text-indigo-600': tab === 'monitor', 'border-transparent text-gray-500 hover:border-gray-300': tab !== 'monitor'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm"><?= $edit_monitor ? 'Edit' : 'Add' ?> Monitor</button><button @click="tab = 'settings'" :class="{'border-indigo-500 text-indigo-600': tab === 'settings', 'border-transparent text-gray-500 hover:border-gray-300': tab !== 'settings'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Settings</button><button @click="tab = 'proxmox'" :class="{'border-indigo-500 text-indigo-600': tab === 'proxmox', 'border-transparent text-gray-500 hover:border-gray-300': tab !== 'proxmox'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Proxmox</button></nav></div>
            <div x-show="tab === 'monitor'" x-cloak class="mt-6"><form method="POST" class="space-y-4"><input type="hidden" name="id" value="<?= $edit_monitor['id'] ?? '' ?>"><div><label>Name</label><input type="text" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" value="<?= htmlspecialchars($edit_monitor['name'] ?? '') ?>"></div><div><label>IP/Hostname</label><input type="text" name="ip_address" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" value="<?= htmlspecialchars($edit_monitor['ip_address'] ?? '') ?>"></div><div><label>Parent Monitor</label><select name="parent_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"><option value="">None</option><?php foreach ($all_monitors as $m) { if (($edit_monitor['id'] ?? 0) === $m['id']) continue; $selected = ($edit_monitor['parent_id'] ?? '') == $m['id'] ? 'selected' : ''; echo "<option value='{$m['id']}' {$selected}>" . htmlspecialchars($m['name']) . "</option>"; } ?></select></div><div class="flex gap-4"><button type="submit" name="save_monitor" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700"><?= $edit_monitor ? 'Update' : 'Save' ?></button><?php if ($edit_monitor) echo '<a href="index.php" class="w-full text-center bg-white border border-gray-300 py-2 px-4 rounded-md hover:bg-gray-50">Cancel</a>'; ?></div></form></div>
            <div x-show="tab === 'settings'" x-cloak class="mt-6"><form method="POST" class="space-y-6"><div class="space-y-4"><h3 class="text-lg font-medium">Email Notifications</h3><div class="flex items-start"><div class="flex items-center h-5"><input id="enable_email" name="enable_email" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?=($settings['enable_email']??'0')==='1'?'checked':''?>></div><div class="ml-3 text-sm"><label for="enable_email" class="font-medium">Enable</label></div></div><div><label>Recipient Email</label><input type="email" name="email_to" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['email_to']??'')?>"></div><div class="grid md:grid-cols-2 gap-4"><div><label>SMTP Host</label><input type="text" name="smtp_host" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['smtp_host']??'')?>"></div><div><label>SMTP Port</label><input type="number" name="smtp_port" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['smtp_port']??'587')?>"></div></div><div class="grid md:grid-cols-2 gap-4"><div><label>SMTP Username</label><input type="text" name="smtp_user" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['smtp_user']??'')?>"></div><div><label>SMTP Password</label><input type="password" name="smtp_pass" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['smtp_pass']??'')?>"></div></div><div><label>Encryption</label><select name="smtp_encryption" class="mt-1 block w-full border-gray-300 rounded-md"><option value="none" <?=($settings['smtp_encryption']??'')==='none'?'selected':''?>>None</option><option value="tls" <?=($settings['smtp_encryption']??'tls')==='tls'?'selected':''?>>TLS</option><option value="ssl" <?=($settings['smtp_encryption']??'')==='ssl'?'selected':''?>>SSL</option></select></div></div><hr><div class="space-y-4"><h3 class="text-lg font-medium">Discord Notifications</h3><div class="flex items-start"><div class="flex items-center h-5"><input id="enable_discord" name="enable_discord" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?=($settings['enable_discord']??'0')==='1'?'checked':''?>></div><div class="ml-3 text-sm"><label for="enable_discord" class="font-medium">Enable</label></div></div><div><label>Discord Webhook URL</label><input type="url" name="discord_webhook_url" class="mt-1 block w-full shadow-sm border-gray-300 rounded-md" value="<?=htmlspecialchars($settings['discord_webhook_url']??'')?>"></div></div><button type="submit" name="save_settings" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700">Save Settings</button></form></div>
            <div x-show="tab === 'proxmox'" x-cloak class="mt-6"><h3 class="text-lg font-medium"><?= $edit_proxmox_server ? 'Edit' : 'Add' ?> Proxmox Server</h3><form method="POST" class="space-y-4 mt-4"><input type="hidden" name="proxmox_id" value="<?=$edit_proxmox_server['id']??''?>"><div><label>Name</label><input type="text" name="proxmox_name" required class="mt-1 block w-full border-gray-300 rounded-md" value="<?=htmlspecialchars($edit_proxmox_server['name']??'')?>"></div><div class="grid grid-cols-3 gap-4"><div class="col-span-2"><label>Hostname/IP</label><input type="text" name="proxmox_hostname" required class="mt-1 block w-full border-gray-300 rounded-md" value="<?=htmlspecialchars($edit_proxmox_server['hostname']??'')?>"></div><div><label>Port</label><input type="number" name="proxmox_port" required class="mt-1 block w-full border-gray-300 rounded-md" value="<?=htmlspecialchars($edit_proxmox_server['port']??'8006')?>"></div></div><div><label>Username (e.g. root@pam)</label><input type="text" name="proxmox_username" required class="mt-1 block w-full border-gray-300 rounded-md" value="<?=htmlspecialchars($edit_proxmox_server['username']??'')?>"></div><div><label>API Token</label><input type="password" name="proxmox_api_token" required class="mt-1 block w-full border-gray-300 rounded-md" value="<?=htmlspecialchars($edit_proxmox_server['api_token']??'')?>"></div><div class="flex items-start"><div class="flex items-center h-5"><input id="proxmox_verify_ssl" name="proxmox_verify_ssl" type="checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" <?=!isset($edit_proxmox_server)||($edit_proxmox_server['verify_ssl']??'1')=='1'?'checked':''?>></div><div class="ml-3 text-sm"><label for="proxmox_verify_ssl" class="font-medium">Verify SSL</label></div></div><div class="flex gap-4"><button type="submit" name="save_proxmox" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700"><?= $edit_proxmox_server ? 'Update' : 'Save' ?></button><?php if ($edit_proxmox_server) echo '<a href="index.php" class="w-full text-center bg-white border border-gray-300 py-2 px-4 rounded-md hover:bg-gray-50">Cancel</a>'; ?></div></form><hr class="my-6"><h3 class="text-lg font-medium">Configured Servers</h3><div class="space-y-2 mt-4"><?php if(empty($proxmox_servers)) echo '<p class="text-sm text-gray-500">No servers configured.</p>'; else foreach($proxmox_servers as $server):?><div class="p-2 border rounded-md flex justify-between items-center"><div><p class="font-semibold"><?=htmlspecialchars($server['name'])?></p><p class="text-xs text-gray-600"><?=htmlspecialchars($server['hostname'])?></p></div><div class="flex items-center space-x-2"><form method="POST" class="inline"><input type="hidden" name="proxmox_id" value="<?=$server['id']?>"><button type="submit" name="sync_proxmox" class="text-sm text-green-600 hover:underline">Sync</button></form><a href="?edit_proxmox=<?=$server['id']?>" class="text-sm text-blue-500 hover:underline">Edit</a><form method="POST" onsubmit="return confirm('Delete this server config?');" class="inline"><input type="hidden" name="proxmox_id" value="<?=$server['id']?>"><button type="submit" name="delete_proxmox" class="text-sm text-red-500 hover:underline">Delete</button></form></div></div><?php endforeach;?></div></div>
        </div>
    </div>
    <footer class="mt-12 pt-4 border-t text-center text-sm text-gray-500"><p>FamPing</p></footer>
</div>
</body>
</html>
