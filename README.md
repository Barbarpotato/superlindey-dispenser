# Deploy API

API sederhana berbasis PHP untuk meng-clone repository [app-runner](https://github.com/Barbarpotato/app-runner.git) menjadi sebuah **instance** (folder) baru di level `../`. Setiap instance memiliki nama folder sesuai `instance_name` yang dikirim pada body request.

## Daftar File

| File        | Keterangan                                                                      |
| ----------- | ------------------------------------------------------------------------------- |
| `index.php` | Endpoint API. Validasi API key & `instance_name`, lalu menjalankan `deploy.sh`. |
| `deploy.sh` | Script shell yang melakukan `git clone` ke folder `../<instance_name>`.         |

## Cara Kerja

```
POST /deploy/index.php
        │
        ├─ 1. Cek method harus POST
        ├─ 2. Cek API key (header X-API-Key / query ?api_key=)
        ├─ 3. Ambil instance_name dari body (JSON / form)
        ├─ 4. Validasi format instance_name  ( A-Z a-z 0-9 _ - )
        ├─ 5. Kalau folder ../<instance_name> sudah ada -> 403
        └─ 6. Jalankan deploy.sh <instance_name> -> git clone
```

Repository akan di-clone ke **satu level di atas** folder `deploy`, contoh:

```
DocumentRoot/
├── deploy/            <- API ada di sini
│   ├── index.php
│   └── deploy.sh
├── hello_world/       <- hasil clone (instance_name = hello_world)
└── app-runner/        <- instance lain
```

## Konfigurasi

Sebelum dipakai, buka `index.php` dan ganti nilai `API_KEY`:

```php
const API_KEY = 'ubah-saya-dengan-key-rahasia-yang-kuat';
```

Disarankan memakai key acak yang kuat:

```bash
openssl rand -hex 32
```

### Prasyarat Server

- PHP terpasang dan dapat menjalankan `exec()` (tidak di-disable di `php.ini`).
- `git` dan `bash` tersedia di server.
- User web server (mis. `www-data`) punya **izin tulis** ke folder `../`.
- `deploy.sh` dapat dieksekusi:

    ```bash
    chmod +x deploy.sh
    ```

## Penggunaan

### Request

| Item   | Nilai                                                                     |
| ------ | ------------------------------------------------------------------------- |
| Method | `POST`                                                                    |
| URL    | `/deploy/index.php`                                                       |
| Header | `X-API-Key: <API_KEY>` (atau query `?api_key=<API_KEY>`)                  |
| Body   | `{ "instance_name": "<nama>" }` (JSON) atau `instance_name=<nama>` (form) |

### Parameter Body

| Field           | Tipe   | Wajib | Aturan                                                             |
| --------------- | ------ | ----- | ------------------------------------------------------------------ |
| `instance_name` | string | ✅    | Hanya huruf, angka, `_`, dan `-`. Menjadi nama folder hasil clone. |

### Contoh — cURL (JSON)

```bash
curl -X POST https://server-anda/deploy/index.php \
  -H "X-API-Key: ubah-saya-dengan-key-rahasia-yang-kuat" \
  -H "Content-Type: application/json" \
  -d '{"instance_name": "hello_world"}'
```

### Contoh — cURL (form-urlencoded)

```bash
curl -X POST "https://server-anda/deploy/index.php?api_key=ubah-saya-dengan-key-rahasia-yang-kuat" \
  -d "instance_name=hello_world"
```

## Respon

### ✅ 200 — Berhasil

```json
{
	"status": "success",
	"message": "Deploy berhasil.",
	"instance_name": "hello_world",
	"exit_code": 0,
	"started_at": "2026-06-15T09:20:00+07:00",
	"ended_at": "2026-06-15T09:20:07+07:00",
	"output": [
		"==> Instance     : hello_world",
		"==> Cloning repository...",
		"Cloning into '.../hello_world'...",
		"==> Selesai (cloned) : 2026-06-15 09:20:07"
	]
}
```

### 🚫 403 — Instance sudah ada

```json
{
	"status": "error",
	"message": "instance name already exist",
	"instance_name": "hello_world"
}
```

### ⚠️ 400 — instance_name kosong / format salah

```json
{
	"status": "error",
	"message": "Field \"instance_name\" wajib diisi."
}
```

```json
{
	"status": "error",
	"message": "instance_name hanya boleh berisi huruf, angka, \"_\" dan \"-\"."
}
```

### 🔒 401 — API key salah

```json
{
	"status": "error",
	"message": "API key tidak valid."
}
```

### ❌ 405 — Method bukan POST

```json
{
	"status": "error",
	"message": "Method tidak diizinkan. Gunakan POST."
}
```

## Daftar Kode Status

| HTTP  | Arti                                           |
| ----- | ---------------------------------------------- |
| `200` | Clone berhasil                                 |
| `400` | `instance_name` kosong atau format tidak valid |
| `401` | API key tidak valid                            |
| `403` | Instance dengan nama tersebut sudah ada        |
| `405` | Method bukan POST                              |
| `500` | Script gagal / `deploy.sh` tidak ditemukan     |

## Catatan Keamanan

- API ini **menjalankan perintah shell** di server — jaga kerahasiaan `API_KEY`.
- Gunakan **HTTPS** dan, jika memungkinkan, batasi akses berdasarkan IP.
- `instance_name` divalidasi dengan whitelist karakter untuk mencegah _path traversal_ (mis. `../`).
- Jangan commit `API_KEY` asli ke repository publik.
