<?php
/*
================================================================================
File: check_monitors.php
Description: This script performs the checks and sends notifications.
             This should be run on a schedule (e.g., a cron job).
================================================================================
*/

require_once 'config.php';
echo "Starting FamPing check at " . date('Y-m-d H:i:s') . "\n";

$db = getDbConnection();

// 1. Fetch all monitors and build the tree structure
$monitors_raw = $db->query("SELECT * FROM monitors")->fetchAll(PDO::FETCH_ASSOC);

if (empty($monitors_raw)) {
    echo "No monitors to check. Exiting.\n";
    exit;
}

$monitors_by_id = [];
foreach ($monitors_raw as $m) $monitors_by_id[$m['id']] = $m;

$monitors_tree = [];
foreach ($monitors_by_id as $id => &$monitor) {
    if (empty($monitor['parent_id'])) $monitors_tree[$id] = &$monitor;
    elseif (isset($monitors_by_id[$monitor['parent_id']])) $monitors_by_id[$monitor['parent_id']]['children'][$id] = &$monitor;
}
unset($monitor);

function checkMonitorsRecursively(array $monitors_to_check, PDO $db, array &$current_statuses) {
    foreach ($monitors_to_check as $monitor) {
        echo "Checking {$monitor['name']} ({$monitor['ip_address']})... ";
        $status_string = pingHost($monitor['ip_address']) ? 'up' : 'down';
        $current_statuses[$monitor['id']] = $status_string;
        
        $db->prepare("UPDATE monitors SET last_status = ?, last_check = datetime('now', 'localtime') WHERE id = ?")->execute([$status_string, $monitor['id']]);
        $db->prepare("INSERT INTO ping_history (monitor_id, status, check_time) VALUES (?, ?, datetime('now', 'localtime'))")->execute([$monitor['id'], $status_string]);

        echo "Status: {$status_string}\n";

        if (!empty($monitor['children'])) {
            checkMonitorsRecursively($monitor['children'], $db, $current_statuses);
        }
    }
}

// 2. Execute the checks
$current_statuses = [];
echo "--- Starting Hierarchical Check ---\n";
checkMonitorsRecursively($monitors_tree, $db, $current_statuses);
echo "--- Check Finished ---\n";

// 3. Process notifications
foreach ($monitors_raw as $monitor) {
    $current_status = $current_statuses[$monitor['id']];
    $was_notifying = (bool)$monitor['is_notifying'];

    if ($current_status === 'down') {
        if ($was_notifying) continue;
        
        $parent_is_down = false;
        if (!empty($monitor['parent_id'])) {
            if (($current_statuses[$monitor['parent_id']] ?? 'up') === 'down') {
                $parent_is_down = true;
                echo "-> Suppressing notification for {$monitor['name']} because parent is down.\n";
            }
        }
        if (!$parent_is_down) {
            echo "-> Sending DOWN notification for {$monitor['name']}.\n";
            sendNotification($monitor, 'DOWN', $db);
            $db->prepare("UPDATE monitors SET is_notifying = 1 WHERE id = ?")->execute([$monitor['id']]);
        }
    } else { // status is 'up'
        if ($was_notifying) {
            echo "-> Sending UP (recovery) notification for {$monitor['name']}.\n";
            sendNotification($monitor, 'UP', $db);
        }
        $db->prepare("UPDATE monitors SET is_notifying = 0 WHERE id = ?")->execute([$monitor['id']]);
    }
}

// 4. Prune old history data
echo "Pruning old history data...\n";
$db->exec("DELETE FROM ping_history WHERE check_time < datetime('now', '-30 days')");

echo "FamPing check finished at " . date('Y-m-d H:i:s') . "\n";
?>
