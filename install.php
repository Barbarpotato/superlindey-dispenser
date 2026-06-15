<?php
/**
 * install.php — install (non-interaktif) sebuah instance yang SUDAH di-clone,
 * dengan memanggil CLI `setup.php install` milik instance. Sekali pakai: ditolak
 * (403) bila instance sudah punya _db_config.php.
 *
 *   POST /deploy/install.php
 *   Header: X-API-Key: <API_KEY>   (atau ?api_key=<API_KEY>)
 *   Body:   { "instance_name", "config_url", "db_host", "db_name", "db_user",
 *             "db_pass", "auth_db_name", "admin_username", "admin_password" }
 *
 * Mengembalikan JSON dari setup.php, termasuk `setup_hmac_secret` (sekali saja).
 *
 * Catatan: untuk clone + install sekaligus, gunakan deploy.php dengan menyertakan
 * field install yang sama pada body.
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

require_post_with_api_key();
$body     = read_request_body();
$instance = valid_instance_name($body);

[$code, $json] = run_install($instance, $body);
respond($code, $json);
