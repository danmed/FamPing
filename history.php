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
<html lang="en" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPing - History for <?= htmlspecialchars($monitor['name'] ?? 'Monitor') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style> body { font-family: 'Inter', sans-serif; } </style>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <script>
        // Inline script in head to prevent Flash of Unstyled Content (FOUC) for dark mode
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-800 dark:bg-gray-900 dark:text-gray-200">

<div class="container mx-auto p-4 md:p-8">

    <header class="mb-8 flex justify-between items-center">
         <div>
            <a href="index.php" class="text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Back to Dashboard</a>
            <?php if ($monitor): ?>
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mt-2">Ping History: <?= htmlspecialchars($monitor['name']) ?></h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Showing the last 200 checks for <?= htmlspecialchars($monitor['ip_address']) ?></p>
            <?php else: ?>
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mt-2">Ping History</h1>
            <?php endif; ?>
        </div>
         <button id="theme-toggle" type="button" class="text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-2.5">
            <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
            <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        </button>
    </header>

    <?php if ($error_message): ?>
    <div class="bg-red-100 border-red-400 text-red-700 dark:bg-red-900 dark:text-red-300 dark:border-red-800 px-4 py-3 rounded-lg relative mb-6" role="alert">
        <strong class="font-bold">Application Error!</strong>
        <span class="block sm:inline"><?= $error_message ?></span>
    </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Check Time</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="2" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 text-center">No history records found for this monitor.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $record): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if ($record['status'] === 'up'): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300">
                                            UP
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300">
                                            DOWN
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?= date('Y-m-d H:i:s', strtotime($record['check_time'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <footer class="mt-12 pt-4 border-t text-center text-sm text-gray-500 dark:border-gray-700">
        <p>PHPing - A PHP IP Monitor App</p>
    </footer>

</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            themeToggleLightIcon.classList.remove('hidden');
        } else {
            themeToggleDarkIcon.classList.remove('hidden');
        }

        var themeToggleBtn = document.getElementById('theme-toggle');

        themeToggleBtn.addEventListener('click', function() {
            themeToggleDarkIcon.classList.toggle('hidden');
            themeToggleLightIcon.classList.toggle('hidden');
            if (localStorage.getItem('color-theme')) {
                if (localStorage.getItem('color-theme') === 'light') {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                }
            } else {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                }
            }
        });
    });
</script>
</body>
</html>

