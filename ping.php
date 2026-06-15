<?php
/**
 * ping.php — health check control plane + daftar instance yang dikelola.
 *
 *   GET (atau POST) /deploy/ping.php
 *   Header: X-API-Key: <API_KEY>   (atau ?api_key=<API_KEY>)
 *
 * Instance = folder sejajar `deploy` yang berisi `setup.php` (penanda hasil
 * clone app-runner). Folder lain (proyek non-instance) diabaikan.
 *
 * Respons memuat daftar nama folder + status (sudah ter-install atau belum).
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

// Auth — method bebas (GET/POST), cukup API key di header atau query.
$provided = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if (!is_string($provided) || !hash_equals(API_KEY, $provided)) {
    respond(401, ['status' => 'error', 'message' => 'API key tidak valid.']);
}

$base      = dirname(__DIR__);
$instances = [];
foreach (scandir($base) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $dir = $base . '/' . $entry;
    if (is_dir($dir) && is_file($dir . '/setup.php')) {
        $instances[] = [
            'name'      => $entry,
            'installed' => is_file($dir . '/_db_config.php'),
        ];
    }
}

// Urutkan berdasarkan nama agar output stabil.
usort($instances, fn($a, $b) => strcmp($a['name'], $b['name']));

respond(200, [
    'status'    => 'ok',
    'time'      => date('c'),
    'count'     => count($instances),
    'instances' => $instances,
]);
