<?php
/**
 * deploy.php — clone repo app-runner menjadi instance baru di ../<instance_name>,
 * dan (opsional) langsung meng-install-nya dalam satu panggilan.
 *
 *   POST /deploy/deploy.php
 *   Header: X-API-Key: <API_KEY>        (atau query ?api_key=<API_KEY>)
 *
 *   Body (clone saja):
 *     { "instance_name": "hello_world" }
 *
 *   Body (clone + install — cukup sertakan config_url + kredensial):
 *     { "instance_name": "hello_world", "config_url": "...", "db_host": "...",
 *       "db_name": "...", "db_user": "...", "db_pass": "...", "auth_db_name": "...",
 *       "admin_username": "...", "admin_password": "..." }
 *
 * Bila install diminta tetapi gagal, folder yang baru di-clone akan di-rollback
 * (dihapus) agar instance bisa dibuat ulang dengan bersih.
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

const SCRIPT = __DIR__ . '/deploy.sh';

require_post_with_api_key();
$body     = read_request_body();
$instance = valid_instance_name($body);

// Tolak bila folder instance sudah ada.
$targetDir = instance_dir($instance);
if (file_exists($targetDir)) {
    respond(403, ['status' => 'error', 'message' => 'instance name already exist', 'instance_name' => $instance]);
}
if (!is_file(SCRIPT)) {
    respond(500, ['status' => 'error', 'message' => 'Script deploy.sh tidak ditemukan.']);
}

// ── 1) CLONE ────────────────────────────────────────────────────────────────
$cmd = SAFE_PATH_EXPORT . 'bash ' . escapeshellarg(SCRIPT) . ' ' . escapeshellarg($instance) . ' 2>&1';
$startedAt = date('c');
exec($cmd, $output, $exitCode);

// Folder bisa muncul antara pengecekan di atas dan clone -> deploy.sh keluar dengan kode 3.
if ($exitCode === 3) {
    respond(403, ['status' => 'error', 'message' => 'instance name already exist', 'instance_name' => $instance]);
}
if ($exitCode !== 0) {
    $message = $exitCode === 127
        ? "Deploy gagal: 'git' tidak ditemukan di server. Install git terlebih dahulu."
        : 'Deploy gagal.';
    respond(500, [
        'status'        => 'error',
        'message'       => $message,
        'instance_name' => $instance,
        'exit_code'     => $exitCode,
        'started_at'    => $startedAt,
        'ended_at'      => date('c'),
        'output'        => $output,
    ]);
}

// ── 2) INSTALL (opsional) ─────────────────────────────────────────────────────
// Dipicu hanya bila body menyertakan config_url; selain itu tetap clone-only.
$installResult = null;
if (isset($body['config_url'])) {
    [$icode, $ijson] = run_install($instance, $body);
    $installResult = $ijson;

    if ($icode >= 400) {
        // Rollback: hapus folder yang baru saja di-clone agar bisa diulang bersih.
        rrmdir($targetDir);
        respond($icode, [
            'status'        => 'error',
            'message'       => 'Clone berhasil tetapi install gagal. Instance di-rollback (folder dihapus).',
            'instance_name' => $instance,
            'rolled_back'   => true,
            'install'       => $ijson,
            'clone_output'  => $output,
        ]);
    }
}

// ── 3) SUKSES ─────────────────────────────────────────────────────────────────
respond(200, [
    'status'        => 'success',
    'message'       => $installResult ? 'Deploy + install berhasil.' : 'Deploy berhasil.',
    'instance_name' => $instance,
    'exit_code'     => $exitCode,
    'started_at'    => $startedAt,
    'ended_at'      => date('c'),
    'output'        => $output,
    'install'       => $installResult,   // null bila clone-only
]);
