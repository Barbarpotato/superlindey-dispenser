<?php
/**
 * update.php — migrasi schema (plan/apply) sebuah instance, via CLI
 * `setup.php update` milik instance.
 *
 * Dua tahap (stateless):
 *   plan  — hitung selisih schema, kembalikan daftar DDL + plan_token. Read-only.
 *   apply — kirim balik plan_token + daftar id yang disetujui. Server menghitung
 *           ulang selisih, verifikasi token (anti-drift), eksekusi yang disetujui;
 *           bila SELURUH plan disetujui & sukses, kode app di-regenerate.
 *
 *   POST /deploy/update.php
 *   Header: X-API-Key: <API_KEY>   (atau ?api_key=<API_KEY>)
 *   Body (plan):  { "instance_name", "action": "plan",  "config_url" }
 *   Body (apply): { "instance_name", "action": "apply", "config_url",
 *                   "plan_token", "approve": [0,1,2] }
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

require_post_with_api_key();
$body     = read_request_body();
$instance = valid_instance_name($body);

$setup = instance_dir($instance) . '/setup.php';
if (!is_file($setup)) {
    respond(404, ['status' => 'error', 'message' => "Instance '$instance' tidak ditemukan."]);
}

$action = $body['action'] ?? null;
if (!in_array($action, ['plan', 'apply'], true)) {
    respond(400, ['status' => 'error', 'message' => "Field 'action' harus 'plan' atau 'apply'."]);
}
$config_url = $body['config_url'] ?? null;
if (!is_string($config_url) || $config_url === '') {
    respond(400, ['status' => 'error', 'message' => "Field 'config_url' wajib diisi."]);
}

$cmd = SAFE_PATH_EXPORT . 'php ' . escapeshellarg($setup) . ' update ' . escapeshellarg($config_url);

if ($action === 'plan') {
    $cmd .= ' --plan';
} else {
    // apply — butuh plan_token + daftar id yang disetujui
    $token = (isset($body['plan_token']) && is_string($body['plan_token'])) ? $body['plan_token'] : '';
    if ($token === '') {
        respond(400, ['status' => 'error', 'message' => "Field 'plan_token' wajib untuk action 'apply'."]);
    }
    $approve = $body['approve'] ?? [];
    if (!is_array($approve)) {
        respond(400, ['status' => 'error', 'message' => "Field 'approve' harus berupa array id integer."]);
    }
    $ids = [];
    foreach ($approve as $id) {
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            respond(400, ['status' => 'error', 'message' => "Field 'approve' harus berisi id integer."]);
        }
        $ids[] = (int) $id;
    }
    $cmd .= ' --apply';
    $cmd .= ' --plan-token=' . escapeshellarg($token);
    $cmd .= ' --approve='    . escapeshellarg(implode(',', $ids));
}

[$code, $json] = relay_setup_json($cmd);
respond($code, $json);
