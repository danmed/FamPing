<?php
/*
================================================================================
File: history.php
Description: Displays the ping history for a specific monitor.
================================================================================
*/

require_once 'config.php';

$monitor_id = $_GET['id'] ?? null;
if (!$monitor_id) {
    header("Location: index.php");
    exit();
}

$db = getDbConnection();
$error_message = null;
$monitor = null;
$history = [];

try {
    // Fetch the monitor's details
    $stmt = $db->prepare("SELECT * FROM monitors WHERE id = ?");
    $stmt->execute([$monitor_id]);
    $monitor = $stmt->fetch();

    if (!$monitor) {
        throw new Exception("Monitor not found.");
    }

    // Fetch the ping history for this monitor
    $stmt = $db->prepare("SELECT * FROM ping_history WHERE monitor_id = ? ORDER BY check_time DESC LIMIT 200");
    $stmt->execute([$monitor_id]);
    $history = $stmt->fetchAll();

} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPing - History for <?= htmlspecialchars($monitor['name'] ?? 'Monitor') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style> body { font-family: 'Inter', sans-serif; } </style>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
</head>
<body class="bg-gray-50 text-gray-800">

<div class="container mx-auto p-4 md:p-8">

    <header class="mb-8">
        <a href="index.php" class="text-indigo-600 hover:underline">&larr; Back to Dashboard</a>
        <?php if ($monitor): ?>
        <h1 class="text-4xl font-bold text-gray-900 mt-2">Ping History: <?= htmlspecialchars($monitor['name']) ?></h1>
        <p class="text-gray-600 mt-1">Showing the last 200 checks for <?= htmlspecialchars($monitor['ip_address']) ?></p>
        <?php else: ?>
        <h1 class="text-4xl font-bold text-gray-900 mt-2">Ping History</h1>
        <?php endif; ?>
    </header>

    <?php if ($error_message): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
        <strong class="font-bold">Application Error!</strong>
        <span class="block sm:inline"><?= $error_message ?></span>
    </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check Time</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="2" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No history records found for this monitor.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $record): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if ($record['status'] === 'up'): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            UP
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            DOWN
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('Y-m-d H:i:s', strtotime($record['check_time'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <footer class="mt-12 pt-4 border-t text-center text-sm text-gray-500">
        <p>PHPing - A PHP IP Monitor App</p>
    </footer>

</div>

</body>
</html>

