# Deploy Control Plane

API berbasis PHP untuk mengelola siklus hidup instance [app-runner](https://github.com/Barbarpotato/app-runner.git) dari **satu pintu**. Setiap instance adalah sebuah folder hasil clone di level `../` (sejajar dengan folder `deploy` ini).

Semua endpoint berjalan di server yang sama dengan instance dan **memanggil CLI `setup.php` milik instance** (`php ../<instance_name>/setup.php ...`) — tidak ada endpoint HTTP yang terbuka di dalam tiap instance. Satu `API_KEY` menjaga seluruh operasi.

## Daftar File

| File          | Keterangan                                                                                            |
| ------------- | ----------------------------------------------------------------------------------------------------- |
| `deploy.php`  | Clone repo app-runner menjadi instance baru di `../<instance_name>`, **opsional langsung install**.    |
| `install.php` | Install instance (yang sudah di-clone) secara non-interaktif. Sekali pakai.                            |
| `update.php`  | Migrasi schema instance dengan alur **plan/apply** (lihat dulu rencananya, setujui, baru jalan).       |
| `uninstall.php`| Hapus instance permanen: hapus folder, opsional drop database.                                        |
| `ping.php`    | Health check + daftar instance (folder) yang dikelola control plane.                                   |
| `lib.php`     | Konfigurasi (`API_KEY`) & fungsi bersama untuk semua endpoint di atas.                                 |
| `deploy.sh`   | Script shell `git clone` yang dipanggil oleh `deploy.php`.                                             |

## Arsitektur

```
                 POST /deploy/<endpoint>   (Header: X-API-Key)
                          │
        ┌─────────────────┼──────────────────┐
        ▼                 ▼                   ▼
   deploy.php        install.php          update.php
   (git clone)   (setup.php install)  (setup.php update --plan/--apply)
        │                 │                   │
        ▼                 ▼                   ▼
   ../<instance>/   ../<instance>/setup.php (CLI, non-interaktif → JSON)
```

```
DocumentRoot/
├── deploy/             <- API control-plane (folder ini)
│   ├── deploy.php
│   ├── install.php
│   ├── update.php
│   └── deploy.sh
├── hello_world/        <- instance hasil clone + install
└── app-runner/         <- instance lain
```

## Konfigurasi

`API_KEY` dipusatkan di **`lib.php`** — cukup ganti di satu tempat ini untuk semua endpoint:

```php
// lib.php
const API_KEY = 'ganti-dengan-key-rahasia-yang-kuat';
```

Buat key acak:

```bash
openssl rand -hex 32
```

### Prasyarat Server

- PHP CLI (`php`) tersedia di `PATH` dan dapat menjalankan `exec()`/`shell_exec()` (tidak di-disable di `php.ini`).
- `git` dan `bash` tersedia (untuk `deploy.php`).
- User web server (mis. `www-data`) punya **izin tulis** ke folder `../` (untuk clone) dan ke folder instance (untuk menulis `_db_config.php`, `channels/`, `Library/`).
- `deploy.sh` dapat dieksekusi: `chmod +x deploy.sh`.

---

## 0. Ping (health check + daftar instance)

Mengembalikan daftar instance yang dikelola — yaitu folder sejajar `deploy` yang
berisi `setup.php` (penanda hasil clone app-runner). Folder lain diabaikan.

| Item   | Nilai                                                       |
| ------ | ---------------------------------------------------------- |
| Method | `GET` atau `POST`                                          |
| URL    | `/deploy/ping.php`                                         |
| Header | `X-API-Key: <API_KEY>` (atau query `?api_key=<API_KEY>`)  |

```bash
curl "https://server-anda/deploy/ping.php?api_key=<API_KEY>"
```

Respon:

```json
{
  "status": "ok",
  "time": "2026-06-15T14:45:18+08:00",
  "count": 2,
  "instances": [
    { "name": "hello_world", "installed": true },
    { "name": "shop_demo",   "installed": false }
  ]
}
```

`installed` = `true` bila instance sudah punya `_db_config.php` (sudah di-install).

---

## 1. Deploy (clone instance, opsional + install)

| Item   | Nilai                                                       |
| ------ | ---------------------------------------------------------- |
| Method | `POST`                                                     |
| URL    | `/deploy/deploy.php`                                       |
| Header | `X-API-Key: <API_KEY>` (atau query `?api_key=<API_KEY>`)  |

### Clone saja

```bash
curl -X POST https://server-anda/deploy/deploy.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{"instance_name": "hello_world"}'
```

Repo di-clone ke `../<instance_name>`. Gagal (`403`) bila folder sudah ada.

### Clone + install sekaligus (satu panggilan)

Cukup sertakan `config_url` + kredensial install pada body yang sama (field
identik dengan endpoint [install](#2-install-bootstrap-instance)):

```bash
curl -X POST https://server-anda/deploy/deploy.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "instance_name":  "hello_world",
    "config_url":     "raw.githubusercontent.com/anda/config/main/config.json",
    "db_host":        "mysql",   "db_name":      "hello_world",
    "db_user":        "root",    "db_pass":      "root",
    "auth_db_name":   "hello_world_auth",
    "admin_username": "admin",   "admin_password": "rahasia"
  }'
```

- Bila `config_url` **ada** → clone lalu install; respons memuat blok `install`
  (termasuk `setup_hmac_secret` sekali saja).
- Bila `config_url` **tidak ada** → hanya clone (kompatibel dengan pemakaian lama).
- Bila clone sukses tetapi install **gagal** → folder yang baru di-clone
  **di-rollback** (dihapus) dan respons memuat `"rolled_back": true`, agar instance
  bisa dibuat ulang dengan bersih.

---

## 2. Install (bootstrap instance)

Menjalankan `php ../<instance>/setup.php install <config_url> --db-... --admin-...`. **Sekali pakai**: ditolak (`403`) bila instance sudah punya `_db_config.php`.

| Item   | Nilai                                                       |
| ------ | ---------------------------------------------------------- |
| Method | `POST`                                                     |
| URL    | `/deploy/install.php`                                      |
| Header | `X-API-Key: <API_KEY>`                                     |

### Body

| Field            | Wajib | Keterangan                                  |
| ---------------- | ----- | ------------------------------------------- |
| `instance_name`  | ✅    | Folder instance di `../` (`A-Z a-z 0-9 _ -`). |
| `config_url`     | ✅    | URL `config.json`.                          |
| `db_host`        | ✅    | Host MySQL.                                 |
| `db_name`        | ✅    | Nama database bisnis.                       |
| `db_user`        | ✅    | User MySQL.                                 |
| `db_pass`        | ➖    | Password MySQL (boleh string kosong).       |
| `auth_db_name`   | ✅    | Nama database auth.                         |
| `admin_username` | ✅    | Username admin app-runner.                  |
| `admin_password` | ✅    | Password admin app-runner.                  |

```bash
curl -X POST https://server-anda/deploy/install.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "instance_name":  "hello_world",
    "config_url":     "raw.githubusercontent.com/anda/config/main/config.json",
    "db_host":        "mysql",
    "db_name":        "hello_world",
    "db_user":        "root",
    "db_pass":        "root",
    "auth_db_name":   "hello_world_auth",
    "admin_username": "admin",
    "admin_password": "rahasia"
  }'
```

### Respon (200)

```json
{
  "ok": true,
  "message": "Installation complete. SAVE the secret below — it is shown only once.",
  "databases": { "business": "hello_world", "auth": "hello_world_auth" },
  "tables_created": ["users", "orders"],
  "foreign_key_errors": [],
  "admin_username": "admin",
  "setup_hmac_secret": "f3a9...c1"
}
```

> ⚠️ **`setup_hmac_secret` hanya ditampilkan sekali** — disimpan oleh server di `_db_config.php` instance dan dipakai sebagai kunci `plan_token` saat update. Catat bila Anda butuh.

---

## 3. Update (migrasi schema — plan/apply)

Memanggil `php ../<instance>/setup.php update <config_url> --plan` lalu `--apply`. Dua tahap, stateless.

| Item   | Nilai                  |
| ------ | ---------------------- |
| Method | `POST`                 |
| URL    | `/deploy/update.php`   |
| Header | `X-API-Key: <API_KEY>` |

### Tahap 1 — `plan` (read-only)

```bash
curl -X POST https://server-anda/deploy/update.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{ "instance_name": "hello_world", "action": "plan",
        "config_url": "raw.githubusercontent.com/anda/config/main/config.json" }'
```

Respon:

```json
{
  "ok": true,
  "up_to_date": false,
  "plan_token": "9b1c...e7",
  "actions": [
    { "id": 0, "sql": "ALTER TABLE `orders` ADD COLUMN `note` TEXT NULL" },
    { "id": 1, "sql": "CREATE TABLE `invoices` (...)" }
  ]
}
```

### Tahap 2 — `apply`

Kirim balik `plan_token` + `approve` (daftar `id` yang disetujui — boleh sebagian).

```bash
curl -X POST https://server-anda/deploy/update.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{ "instance_name": "hello_world", "action": "apply",
        "config_url": "raw.githubusercontent.com/anda/config/main/config.json",
        "plan_token": "9b1c...e7", "approve": [0, 1] }'
```

Respon:

```json
{
  "ok": true,
  "applied": [
    { "id": 0, "sql": "ALTER TABLE ...", "status": "executed" },
    { "id": 1, "sql": "CREATE TABLE ...", "status": "executed" }
  ],
  "code_regenerated": true,
  "note": null
}
```

Catatan:

- **Anti-drift**: bila schema berubah antara `plan` dan `apply`, `plan_token` tidak cocok → `409`. Jalankan `plan` ulang.
- **Approve sebagian**: hanya `id` yang disetujui yang dieksekusi; sisanya `skipped`. Kode app **tidak** di-regenerate kecuali SELURUH plan disetujui & sukses (agar kode tidak merujuk kolom yang belum dibuat).
- DDL MySQL auto-commit per statement, jadi eksekusi bersifat best-effort berurutan (bukan transaksi atomik); hasil dilaporkan per statement.

---

## 4. Uninstall (hapus instance permanen)

Folder instance **selalu** dihapus. Database hanya di-drop bila `drop_database`
bernilai `true`. Bila di-drop, prosesnya dijalankan **sebelum** folder dihapus
karena butuh `_db_config.php` di dalam folder.

| Item   | Nilai                    |
| ------ | ------------------------ |
| Method | `POST`                   |
| URL    | `/deploy/uninstall.php`  |
| Header | `X-API-Key: <API_KEY>`   |

### Body

| Field           | Wajib | Default | Keterangan                                            |
| --------------- | ----- | ------- | ----------------------------------------------------- |
| `instance_name` | ✅    | —       | Folder instance di `../`.                             |
| `drop_database` | ➖    | `false` | `true` = ikut drop database; `false` = hapus folder saja. |

### Hapus folder saja (database dipertahankan)

```bash
curl -X POST https://server-anda/deploy/uninstall.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{"instance_name": "hello_world"}'
```

```json
{
  "status": "success",
  "message": "Instance dihapus (folder saja, database dipertahankan).",
  "instance_name": "hello_world",
  "database_dropped": false,
  "database": null
}
```

### Hapus folder + database

```bash
curl -X POST https://server-anda/deploy/uninstall.php \
  -H "X-API-Key: <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{"instance_name": "hello_world", "drop_database": true}'
```

```json
{
  "status": "success",
  "message": "Instance dihapus (folder + database).",
  "instance_name": "hello_world",
  "database_dropped": true,
  "database": {
    "ok": true,
    "dropped": [
      { "name": "hello_world", "type": "business" },
      { "name": "hello_world_auth", "type": "auth" }
    ],
    "warnings": []
  }
}
```

> ⚠️ **Tidak bisa dibatalkan.** Saat `drop_database: true`, drop bersifat
> best-effort (`DROP DATABASE IF EXISTS`); folder tetap dihapus walau ada
> peringatan saat drop DB (mis. MySQL sedang mati) — lihat `database.warnings`.

---

## Daftar Kode Status

| HTTP  | Arti                                                        |
| ----- | ----------------------------------------------------------- |
| `200` | Sukses (plan/apply penuh & bersih)                          |
| `207` | Apply selesai sebagian (ada statement yang gagal)           |
| `400` | Body/parameter tidak valid                                  |
| `401` | API key tidak valid                                         |
| `403` | Instance sudah ada (deploy) / sudah ter-install (install)   |
| `404` | Instance tidak ditemukan / belum di-clone                  |
| `405` | Method bukan POST                                          |
| `409` | `plan_token` tidak cocok — schema berubah, plan ulang       |
| `500` | Kegagalan internal / `setup.php` tidak mengembalikan JSON   |

## Catatan Keamanan

- Endpoint ini **menjalankan perintah shell** di server — jaga kerahasiaan `API_KEY` dan gunakan **HTTPS**, jika perlu batasi akses per-IP.
- `instance_name` divalidasi whitelist (`A-Z a-z 0-9 _ -`) untuk mencegah path traversal; seluruh argumen ke CLI melewati `escapeshellarg`.
- `install.php` bersifat sekali pakai (terkunci oleh keberadaan `_db_config.php`) — tidak ada lagi endpoint install tanpa-autentikasi di dalam instance.
- Jangan commit `API_KEY` asli ke repository publik.

## Penggunaan Manual (tanpa deploy)

CLI `setup.php` tetap bisa dipakai langsung dari dalam folder instance, baik interaktif maupun non-interaktif:

```bash
# Interaktif (akan menanyakan kredensial / konfirmasi)
php setup.php install <config_url>
php setup.php update  <config_url>

# Non-interaktif (sama seperti yang dipakai deploy)
php setup.php install <config_url> --db-host=.. --db-name=.. --db-user=.. \
  --db-pass=.. --auth-db=.. --admin-user=.. --admin-pass=..
php setup.php update  <config_url> --plan
php setup.php update  <config_url> --apply --plan-token=.. --approve=0,1,2

# Teardown
php setup.php uninstall          # interaktif (konfirmasi y/n)
php setup.php uninstall --yes    # non-interaktif (drop DB, JSON) — dipakai uninstall.php
```
