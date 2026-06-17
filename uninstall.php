<?php
/**
 * uninstall.php — hapus sebuah instance secara permanen:
 *   1. Drop database (business + auth) milik instance, via CLI
 *      `setup.php uninstall --yes` (membaca _db_config.php instance).
 *   2. Hapus folder instance di ../<instance_name>.
 *
 *   POST /deploy/uninstall.php
 *   Header: X-API-Key: <API_KEY>   (atau ?api_key=<API_KEY>)
 *   Body:   {
 *     "instance_name":  "hello_world",
 *     "drop_database":  false   // opsional, default false
 *   }
 *
 * Folder instance SELALU dihapus. Database hanya di-drop bila "drop_database"
 * bernilai true; bila false (default) database dibiarkan utuh.
 *
 * ⚠️ OPERASI TIDAK BISA DIBATALKAN. Drop database bersifat best-effort
 * (DROP IF EXISTS); folder tetap dihapus walau ada peringatan saat drop DB.
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

require_post_with_api_key();
$body     = read_request_body();
$instance = valid_instance_name($body);

$dir = instance_dir($instance);
if (!is_dir($dir)) {
    respond(404, ['status' => 'error', 'message' => "Instance '$instance' tidak ditemukan."]);
}

// Opsi: ikut drop database atau tidak (default false = folder saja).
$dropDatabase = filter_var($body['drop_database'] ?? false, FILTER_VALIDATE_BOOLEAN);

// ── 1) Drop database (opsional, best-effort) — harus dijalankan SEBELUM folder
//        dihapus karena butuh _db_config.php yang ada di dalam folder instance.
$dbResult = null;
if ($dropDatabase) {
    $setup = $dir . '/setup.php';
    if (is_file($setup)) {
        $cmd = SAFE_PATH_EXPORT . 'php ' . escapeshellarg($setup) . ' uninstall --yes';
        list(, $dbResult) = relay_setup_json($cmd);
    } else {
        $dbResult = ['ok' => false, 'warnings' => ['setup.php tidak ditemukan; melewati drop database.']];
    }
}

// ── 2) Hapus folder instance (selalu).
rrmdir($dir);
if (is_dir($dir)) {
    respond(500, [
        'status'         => 'error',
        'message'        => 'Folder instance gagal dihapus (cek izin tulis).'
                            . ($dropDatabase ? ' Database mungkin sudah di-drop.' : ''),
        'instance_name'  => $instance,
        'database'       => $dbResult,
    ]);
}

respond(200, [
    'status'         => 'success',
    'message'        => $dropDatabase ? 'Instance dihapus (folder + database).' : 'Instance dihapus (folder saja, database dipertahankan).',
    'instance_name'  => $instance,
    'database_dropped' => $dropDatabase,
    'database'       => $dbResult,   // null bila drop_database=false; selain itu { ok, dropped, warnings }
]);
