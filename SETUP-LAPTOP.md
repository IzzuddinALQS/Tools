# Setup di Laptop Lain

Panduan ini untuk melanjutkan pengembangan **Oracle Data Sync** di laptop atau
mesin baru. Fokusnya konfigurasi `.env` dan database lokal, supaya test bisa
lulus dan panel bisa dibuka.

Semua nilai di bawah diambil dari konfigurasi yang benar-benar dipakai di
laptop referensi, bukan contoh karangan.

> **Jangan pakai `.env.example`.** Berkas itu masih bawaan Laravel
> (`APP_NAME=Laravel`, `DB_CONNECTION=sqlite`, nol konfigurasi tool ini) — ini
> temuan D1 di `PR.md`. Salin manual dari blok di bawah.

---

## 1. Yang dibutuhkan

| Komponen | Versi | Catatan |
| --- | --- | --- |
| PHP | **8.4** | `composer.json` menulis `"php": "^8.4"`, Composer akan menolak di bawah itu |
| Composer | 2.x | — |
| Node.js + npm | versi yang didukung Vite 6 | hanya untuk build frontend |
| PostgreSQL | **14+** | lokal, **port 5433** (bukan 5432) |

Ekstensi `oci8` **tidak perlu di laptop** karena driver yang dipakai saat
pengembangan adalah `mock` (fixture SQLite in-memory). Ekstensi itu hanya
wajib di server yang benar-benar menyentuh Oracle. Karena itulah `composer
install` perlu flag khusus — lihat langkah 3.

---

## 2. Siapkan PostgreSQL lokal

Tool ini memakai **empat database** dengan peran berbeda. Memakai satu database
untuk semuanya akan merusak data yang seharusnya terpisah.

```sql
-- Empat database
CREATE DATABASE ods_meta;              -- metadata tool: dataset, run, token
CREATE DATABASE ods_pg_a;              -- hasil query Oracle
CREATE DATABASE ods_pg_b_armors;       -- salinan untuk aplikasi ARMORS
CREATE DATABASE ods_pg_b_reporting;    -- salinan untuk aplikasi reporting

-- User read-only yang dipakai test.
-- Passwordnya harus cocok dengan TEST_PG_RO_PASSWORD di phpunit.xml.
CREATE ROLE ods_ro LOGIN PASSWORD 'ods_ro_pass'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
GRANT CONNECT ON DATABASE ods_pg_b_armors TO ods_ro;
```

Hak `SELECT` pada schema aplikasi **tidak** perlu di-grant manual — test
memanggil `ReadOnlyProvisioner::grantSelect()`, yang memberikannya sendiri.

Pastikan PostgreSQL benar-benar berjalan di **port 5433**:

```bash
# Cek port
pg_isready -h 127.0.0.1 -p 5433
```

Kalau PostgreSQL Anda di port default 5432, ada dua pilihan: ubah port
PostgreSQL ke 5433, atau sesuaikan `DB_PORT` di `.env` **dan** seluruh
`TEST_PG_PORT` di `phpunit.xml`. Lihat langkah 5 — ini jebakan yang sering
terlewat.

---

## 3. Ambil kode dan pasang dependency

```bash
git clone <url-repo> oracle-data-sync
cd oracle-data-sync

composer install --ignore-platform-req=ext-oci8
```

Flag `--ignore-platform-req=ext-oci8` wajib di laptop karena ekstensi OCI
tidak ada. Tanpa flag itu, Composer berhenti dengan error platform.

Frontend:

```bash
npm install
```

---

## 4. Buat `.env`

Salin blok ini apa adanya ke `.env`, lalu sesuaikan password bila perlu.

```dotenv
# ------------------------------------------------------------------
# Metadata database (WAJIB terpisah dari PostgreSQL A dan B)
# ------------------------------------------------------------------
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=ods_meta
DB_USERNAME=postgres
DB_PASSWORD=postgres

# Driver default untuk sumber data saat membuat Connection baru.
# mock  = Oracle tiruan untuk pengembangan & test
# oracle= Oracle asli (diisi oleh IT di server)
SYNC_DEFAULT_DRIVER=mock
SYNC_FIXTURE_PATH=

APP_NAME="Oracle Data Sync"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

QUEUE_CONNECTION=database
CACHE_STORE=database

# Lock sync memakai cache store yang SHARED antar worker.
# Untuk produksi multi-host WAJIB redis. Nilai ini sekarang benar-benar
# dipakai oleh DatasetLock (sebelumnya hanya konfigurasi mati).
SYNC_LOCK_STORE=database

# TTL lock, dalam detik. Harus lebih besar dari timeout dataset supaya
# lock tidak yatim ketika worker mati mendadak.
SYNC_LOCK_TTL_SECONDS=900

MAIL_MAILER=log
```

Lalu generate kunci aplikasi:

```bash
php artisan key:generate
```

> **`SESSION_ENCRYPT`** sengaja `false` di sini supaya cocok dengan
> `phpunit.xml` dan tidak membuat debugging lokal membingungkan. Untuk
> produksi **wajib** `true`.
>
> Token API sudah dienkripsi dengan `APP_KEY` sebelum masuk session, jadi
> session yang bocor tidak langsung memberi token. Tapi `SESSION_ENCRYPT=true`
> tetap perlu: tanpa itu, session juga memuat `_token`, email, dan jejak
> navigasi tanpa perlindungan apa pun.
>
> issuance token akan **ditolak** kalau `SESSION_ENCRYPT` tidak true — itu
> gagal tertutup yang disengaja, bukan bug.

---

## 5. `phpunit.xml` — jangan lupa

Nilai database test **tidak** dibaca dari `.env`. Semuanya di-hardcode di
`phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="5433"/>
<env name="DB_DATABASE" value="ods_meta"/>
<env name="DB_USERNAME" value="postgres"/>
<env name="DB_PASSWORD" value="postgres"/>
<env name="TEST_PG_HOST" value="127.0.0.1"/>
<env name="TEST_PG_PORT" value="5433"/>
<env name="TEST_PG_USERNAME" value="postgres"/>
<env name="TEST_PG_PASSWORD" value="postgres"/>
<env name="TEST_PG_A_DATABASE" value="ods_pg_a"/>
<env name="TEST_PG_B_DATABASE" value="ods_pg_b_armors"/>
<env name="TEST_PG_B2_DATABASE" value="ods_pg_b_reporting"/>
<env name="TEST_PG_RO_USER" value="ods_ro"/>
<env name="TEST_PG_RO_PASSWORD" value="ods_ro_pass"/>
```

Kalau PostgreSQL lokal Anda berbeda (port, user, password, nama database),
**harus diubah di sini juga**. Mengubah `.env` saja tidak cukup, dan gejalanya
test akan gagal dengan error koneksi yang tidak menjelaskan asal-usulnya.

---

## 6. Migrasi dan data awal

```bash
php artisan migrate
```

Buat akun admin sekaligus data contoh supaya panel langsung bisa dilihat:

```bash
# Windows PowerShell
'require base_path("isi-data-contoh.php");' | php artisan tinker
```

| | |
| --- | --- |
| Email | `admin@ods.local` |
| Password | `ods12345` |
| Role | `admin` |

Skrip itu membuat koneksi mock, PG A, PG B, dua dataset, dan satu token API.
Aman dijalankan berulang karena semuanya `update-or-create`.

Kalau hanya butuh akunnya saja:

```bash
php artisan tinker
require base_path('buat-akun.php');
```

---

## 7. Build frontend

```bash
npm run build
```

---

## 8. Jalankan

```bash
composer serve
```

Lalu buka `http://127.0.0.1:8000`.

Atau, kalau mau panel, queue worker, log, dan Vite sekaligus:

```bash
composer dev
```

> **Di Windows jangan pakai `php artisan serve`.** Perintah itu gagal saat
> bind port karena bug `ServeCommand` di Laravel 13. `composer serve` memanggil
> `php -S` langsung tanpa manipulasi environment, jadi tidak terkena. Detailnya
> ada di README.

Queue worker **wajib** berjalan kalau mau memakai API Tahap 2 — endpoint itu
memborong job ke antrean, dan tanpa worker job menggantung selamanya.

---

## 9. Verifikasi cepat

```bash
php artisan test
```

Harus keluar **230 lulus / 591 assertion**. Kalau tidak, cek urutannya:

| Gejala | Kemungkinan |
| --- | --- |
| `could not connect to server` | PostgreSQL mati, atau port tidak 5433 |
| `database "ods_pg_a" does not exist` | Database langkah 2 belum dibuat |
| `password authentication failed` | `phpunit.xml` tidak disesuaikan dengan PostgreSQL lokal |
| `role "ods_ro" does not exist` | Perintah `CREATE ROLE` di langkah 2 belum dijalankan |

---

## Jebakan yang sudah saya temui

1. **`php artisan test` mengosongkan database.** `RefreshDatabase` ikut menghapus
   akun admin. Setelah test, jalankan lagi `isi-data-contoh.php` kalau mau
   pakai panel.

2. **`phpunit.xml` meng-hardcode kredensial database test.** Ini yang paling
   sering bikin frustrasi di laptop baru — `.env` sudah benar, tapi test tetap
   gagal.

3. **Vite tidak reload `vite.config.js`.** Kalau kamu mengubah config itu,
   `npm run dev` **harus direstart**.

4. **`composer serve` mati setelah 300 detik** sudah diperbaiki lewat
   `Composer\Config::disableProcessTimeout`. Kalau masih terjadi, pastikan
   `composer.json` masih memuat script itu apa adanya.

5. **Nilai `_token` dari halaman Blade tidak selalu cocok** kalau dipakai lewat
   `fetch()` dari console browser. Untuk login manual, isi formnya lalu submit.

---

## Ringkas

```bash
# 1. database
createdb ods_meta; createdb ods_pg_a; createdb ods_pg_b_armors; createdb ods_pg_b_reporting
psql -c "CREATE ROLE ods_ro LOGIN PASSWORD 'ods_ro_pass' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT"
psql -c "GRANT CONNECT ON DATABASE ods_pg_b_armors TO ods_ro"

# 2. dependency
composer install --ignore-platform-req=ext-oci8
npm install

# 3. .env  (salin manual dari langkah 4 — jangan pakai .env.example)
php artisan key:generate

# 4. samakan phpunit.xml dengan PostgreSQL lokal

# 5. database + data awal
php artisan migrate
'require base_path("isi-data-contoh.php");' | php artisan tinker

# 5. database + data awal
php artisan migrate
'require base_path("isi-data-contoh.php");' | php artisan tinker

# 6. build + jalankan
npm run build
composer dev
```

Detail tiap bagian ada di bagian atas dokumen ini.

## Setelah laptop siap

Scheduler dan worker wajib berjalan kalau mau memakai API Tahap 2:

```bash
* * * * * cd /var/www/oracle-data-sync && php artisan schedule:run >> /dev/null 2>&1
php artisan queue:work --queue=sync --tries=1 --timeout=3600
```

`composer dev` sudah menjalankan worker-nya untuk pengembangan. Di server, dua
perintah di atas dijalankan lewat supervisor.

Tanda worker hidup bisa dicek:

```bash
php artisan ods:queue-heartbeat
php artisan schedule:list
```

Kalau heartbeat basi, endpoint API membalas `503` alih-alih membalas `202`
yang tidak akan pernah dikerjakan.

## Ganti password admin

Password bawaan `ods12345` **wajib diganti sebelum dipakai sungguhan**:

1. Masuk ke panel dengan akun itu.
2. Klik **Ganti sandi** di header.
3. Isi password lama, lalu minimal 12 karakter untuk yang baru.

Sesi lain langsung dikeluarkan. Panel adalah satu-satunya tempat untuk
mengaturnya.