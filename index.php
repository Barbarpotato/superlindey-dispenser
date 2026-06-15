<?php
/**
 * index.php — API untuk menjalankan deploy.sh (clone app-runner ke folder instance).
 *
 * Cara pakai:
 *   POST /deploy/index.php
 *   Header:  X-API-Key: <API_KEY>        (atau query ?api_key=<API_KEY>)
 *   Body  :  { "instance_name": "hello_world" }   (JSON)
 *            atau form-urlencoded: instance_name=hello_world
 *
 * Catatan keamanan:
 *   - GANTI nilai API_KEY di bawah dengan key rahasia milik Anda.
 *   - Sebaiknya hanya izinkan via HTTPS dan batasi IP yang boleh mengakses.
 */

header('Content-Type: application/json');

// ====== KONFIGURASI ======
// GANTI dengan key rahasia Anda sendiri (mis. hasil: openssl rand -hex 32)
const API_KEY = 'your-secret-key';
const SCRIPT  = __DIR__ . '/deploy.sh';
// =========================

function respond(int $httpCode, array $payload): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1) Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'status'  => 'error',
        'message' => 'Method tidak diizinkan. Gunakan POST.',
    ]);
}

// 2) Validasi API key (dari header atau query string)
$provided = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if (!is_string($provided) || !hash_equals(API_KEY, $provided)) {
    respond(401, [
        'status'  => 'error',
        'message' => 'API key tidak valid.',
    ]);
}

// 3) Ambil instance_name dari body (JSON atau form-urlencoded)
$raw  = file_get_contents('php://input');
$json = json_decode($raw, true);
$instanceName = '';
if (is_array($json) && isset($json['instance_name'])) {
    $instanceName = $json['instance_name'];
} elseif (isset($_POST['instance_name'])) {
    $instanceName = $_POST['instance_name'];
}
$instanceName = is_string($instanceName) ? trim($instanceName) : '';

if ($instanceName === '') {
    respond(400, [
        'status'  => 'error',
        'message' => 'Field "instance_name" wajib diisi.',
    ]);
}

// 4) Validasi format nama instance (cegah path traversal & karakter aneh)
if (!preg_match('/^[A-Za-z0-9_-]+$/', $instanceName)) {
    respond(400, [
        'status'  => 'error',
        'message' => 'instance_name hanya boleh berisi huruf, angka, "_" dan "-".',
    ]);
}

// 5) Cek apakah folder instance sudah ada -> access denied
$targetDir = dirname(__DIR__) . '/' . $instanceName;
if (file_exists($targetDir)) {
    respond(403, [
        'status'        => 'error',
        'message'       => 'instance name already exist',
        'instance_name' => $instanceName,
    ]);
}

// 6) Pastikan script ada
if (!is_file(SCRIPT)) {
    respond(500, [
        'status'  => 'error',
        'message' => 'Script deploy.sh tidak ditemukan.',
    ]);
}

// 7) Jalankan script dengan instance_name sebagai argumen, tangkap stdout + stderr
$cmd = 'bash ' . escapeshellarg(SCRIPT) . ' ' . escapeshellarg($instanceName) . ' 2>&1';
$startedAt = date('c');
exec($cmd, $output, $exitCode);

// 8) Folder bisa saja muncul antara cek (langkah 5) dan clone -> tangani exit code 3
if ($exitCode === 3) {
    respond(403, [
        'status'        => 'error',
        'message'       => 'instance name already exist',
        'instance_name' => $instanceName,
    ]);
}

// 9) Kembalikan hasil sebagai JSON
$message = $exitCode === 0 ? 'Deploy berhasil.' : 'Deploy gagal.';
if ($exitCode === 127) {
    $message = "Deploy gagal: 'git' tidak ditemukan di server. Install git terlebih dahulu.";
}
respond($exitCode === 0 ? 200 : 500, [
    'status'        => $exitCode === 0 ? 'success' : 'error',
    'message'       => $message,
    'instance_name' => $instanceName,
    'exit_code'     => $exitCode,
    'started_at'    => $startedAt,
    'ended_at'      => date('c'),
    'output'        => $output,
]);
