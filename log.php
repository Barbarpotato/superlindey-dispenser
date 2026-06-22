<?php
/**
 * log.php — operasi log error via API, berdasarkan instance_name.
 *
 *   POST /deploy/log.php
 *   Header: X-API-Key: <API_KEY> (atau ?api_key=<API_KEY>)
 *
 *   Body:
 *     { "instance_name": "hello_world", "action": "read" }
 *     { "instance_name": "hello_world", "action": "clear" }
 *
 *   Response:
 *     { "status": "success", "action": "read", "lines": [...] }
 *     { "status": "success", "action": "clear" }
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

require_post_with_api_key();
$body = read_request_body();
$instance = valid_instance_name($body);

$logFile = instance_dir($instance) . '/logs/error.log';

$action = $body['action'] ?? null;
if (!in_array($action, ['read', 'clear'], true)) {
    respond(400, ['status' => 'error', 'message' => "Field 'action' harus 'read' atau 'clear'."]);
}

if ($action === 'read') {
    if (!is_file($logFile)) {
        respond(200, ['status' => 'success', 'action' => 'read', 'lines' => [], 'message' => 'Log file tidak ditemukan.']);
    }
    $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);
    respond(200, ['status' => 'success', 'action' => 'read', 'lines' => $lines]);
}

// clear
if (is_file($logFile)) {
    file_put_contents($logFile, '');
}
respond(200, ['status' => 'success', 'action' => 'clear']);