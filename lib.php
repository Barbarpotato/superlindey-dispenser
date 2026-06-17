<?php
/**
 * lib.php — konfigurasi & fungsi bersama untuk endpoint deploy control-plane
 * (deploy.php, install.php, update.php).
 */

// ====== KONFIGURASI ======
// GANTI dengan key rahasia Anda (mis. hasil: openssl rand -hex 32).
// Cukup diganti di SATU tempat ini untuk semua endpoint.
const API_KEY = '12345';
// =========================

// PHP exec()/shell_exec() sering memakai PATH minim sehingga `php`/`git`/`bash`
// tidak ditemukan. Prefix ini memastikan lokasi binary umum tersedia.
const SAFE_PATH_EXPORT = 'export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"; ';

/** Emit JSON dan hentikan eksekusi. */
function respond(int $httpCode, array $payload) {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Pastikan request POST + API key valid (header X-API-Key atau query ?api_key). */
function require_post_with_api_key() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['status' => 'error', 'message' => 'Method tidak diizinkan. Gunakan POST.']);
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    if (!is_string($provided) || !hash_equals(API_KEY, $provided)) {
        respond(401, ['status' => 'error', 'message' => 'API key tidak valid.']);
    }
}

/** Baca body request menjadi array (JSON diutamakan, fallback form-urlencoded), atau 400. */
function read_request_body(): array {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) {
        return $json;
    }
    if (!empty($_POST)) {
        return $_POST;
    }
    respond(400, ['status' => 'error', 'message' => 'Body harus berupa JSON object.']);
}

/** Validasi instance_name (whitelist karakter, cegah path traversal). Kembalikan namanya. */
function valid_instance_name(array $body): string {
    $name = (isset($body['instance_name']) && is_string($body['instance_name'])) ? trim($body['instance_name']) : '';
    if ($name === '') {
        respond(400, ['status' => 'error', 'message' => 'Field "instance_name" wajib diisi.']);
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        respond(400, ['status' => 'error', 'message' => 'instance_name hanya boleh huruf, angka, "_" dan "-".']);
    }
    return $name;
}

/** Direktori sebuah instance (sejajar dengan folder deploy ini). */
function instance_dir(string $instance): string {
    return dirname(__DIR__) . '/' . $instance;
}

/**
 * Jalankan perintah lalu relay JSON dari setup.php. setup.php menyertakan field
 * `http_status` yang dipetakan ke kode HTTP. Kembalikan [httpCode, jsonArray].
 */
function relay_setup_json(string $cmd): array {
    $raw  = shell_exec($cmd . ' 2>&1');
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        return [500, ['ok' => false, 'error' => 'setup.php tidak mengembalikan JSON yang valid.', 'raw' => $raw]];
    }
    $code = isset($json['http_status']) ? (int) $json['http_status'] : 200;
    unset($json['http_status']);
    return [$code, $json];
}

/**
 * Install non-interaktif sebuah instance via CLI setup.php-nya.
 * $params memuat: config_url, db_host, db_name, db_user, db_pass, auth_db_name,
 * admin_username, admin_password (db_pass boleh string kosong).
 * Kembalikan [httpCode, jsonArray].
 */
function run_install(string $instance, array $params): array {
    $setup = instance_dir($instance) . '/setup.php';
    if (!is_file($setup)) {
        return [404, ['ok' => false, 'error' => "Instance '$instance' tidak ditemukan atau belum di-clone."]];
    }

    $string_fields = ['config_url', 'db_host', 'db_name', 'db_user', 'db_pass', 'auth_db_name', 'admin_username', 'admin_password'];
    $missing = [];
    foreach ($string_fields as $f) {
        if (!isset($params[$f]) || !is_string($params[$f]) || ($params[$f] === '' && $f !== 'db_pass')) {
            $missing[] = $f;
        }
    }
    if ($missing) {
        return [400, ['ok' => false, 'error' => 'Field install wajib kosong/tidak valid: ' . implode(', ', $missing)]];
    }

    $cmd  = SAFE_PATH_EXPORT;
    $cmd .= 'php ' . escapeshellarg($setup) . ' install ' . escapeshellarg($params['config_url']);
    $cmd .= ' --db-host='    . escapeshellarg($params['db_host']);
    $cmd .= ' --db-name='    . escapeshellarg($params['db_name']);
    $cmd .= ' --db-user='    . escapeshellarg($params['db_user']);
    $cmd .= ' --db-pass='    . escapeshellarg($params['db_pass']);
    $cmd .= ' --auth-db='    . escapeshellarg($params['auth_db_name']);
    $cmd .= ' --admin-user=' . escapeshellarg($params['admin_username']);
    $cmd .= ' --admin-pass=' . escapeshellarg($params['admin_password']);

    return relay_setup_json($cmd);
}

/** Hapus direktori secara rekursif (dipakai untuk rollback clone yang gagal install). */
function rrmdir(string $dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
