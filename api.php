<?php
/*
================================================================================
File: api.php
Description: Provides monitor status and history data in JSON format for AJAX updates.
================================================================================
*/

header('Content-Type: application/json');
require_once 'config.php';

try {
    $db = getDbConnection();
    
    // 1. Fetch all monitors' current status
    $monitors_raw = $db->query("SELECT id, last_status, last_check FROM monitors")->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Fetch the last 30 history records for all monitors in a single query
    $history_by_monitor = [];
    $history_query = "
        SELECT monitor_id, status
        FROM (
            SELECT
                monitor_id,
                status,
                ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY check_time DESC) as rn
            FROM ping_history
        )
        WHERE rn <= 30
    ";
    
    $history_stmt = $db->query($history_query);
    $raw_history = $history_stmt->fetchAll();

    foreach ($raw_history as $record) {
        $mid = $record['monitor_id'];
        if (!isset($history_by_monitor[$mid])) {
            $history_by_monitor[$mid] = [];
        }
        // Prepend to keep the order correct for easy processing in JS
        array_unshift($history_by_monitor[$mid], $record['status']);
    }

    // 3. Combine the data into a single structure
    $response_data = [];
    foreach ($monitors_raw as $monitor) {
        $monitor_id = $monitor['id'];
        $response_data[$monitor_id] = [
            'status' => $monitor['last_status'],
            'last_check' => $monitor['last_check'] ? date('Y-m-d H:i:s', strtotime($monitor['last_check'])) : 'N/A',
            'history' => $history_by_monitor[$monitor_id] ?? []
        ];
    }

    echo json_encode(['success' => true, 'data' => $response_data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
