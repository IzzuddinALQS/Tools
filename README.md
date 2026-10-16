# Oracle Data Sync

Alat untuk menyalin data dari **Oracle Production ke PostgreSQL tanpa pernah
menyentuh Oracle**. Developer menulis query `SELECT` biasa di SQL Editor, menekan
satu tombol, dan data itu masuk ke PostgreSQL A secara otomatis. Dari PostgreSQL A,
tombol **Copy** meneruskan data ke PostgreSQL B yang dipakai aplikasi — dengan
user `read-only`.

```
  ┌──────────────────────┐
  │   Oracle Production  │   hanya akun SELECT-only
  └──────────┬───────────┘
             │  query SELECT ditulis di SQL Editor
             ▼
  ┌──────────────────────┐        tombol "Copy"        ┌──────────────────────┐
  │    PostgreSQL A      │ ──────────────────────────► │    PostgreSQL B      │
  │  (kanonik / mirror)  │                             │  (salinan per app)   │
  └──────────────────────┘                             └──────────┬───────────┘
                                                                     │ user read-only
                                                                     ▼
                                                             ┌──────────────────────┐
                                                             │     Aplikasi        │
                                                             └──────────────────────┘
```

Prinsipnya satu kalimat: **Oracle hanya dibaca, tidak pernah ditulis**. Tool ini
tidak punya kode INSERT, UPDATE, DELETE, atau DDL ke arah Oracle, dan tidak pernah
membuka koneksi dengan hak selain `SELECT`.

---

## Daftar Isi

1. [Peran & Pembagian Tanggung Jawab](#peran--pembagian-tanggung-jawab)
2. [Peringatan APP_KEY](#peringatan-app_key)
3. [Prasyarat](#prasyarat)
4. [Instalasi `oci8` di Windows](#instalasi-oci8-di-windows)
5. [Instalasi `oci8` di Linux (Ubuntu/Debian)](#instalasi-oci8-di-linux-ubuntudebian)
6. [Instalasi Aplikasi](#instalasi-aplikasi)
7. [Menjalankan Lokal dengan Mode MOCK](#menjalankan-lokal-dengan-mode-mock)
8. [Driver `mock`](#driver-mock)
9. [Mode mock vs Mode Oracle](#mode-mock-vs-mode-oracle)
10. [Queue Worker & Scheduler](#queue-worker--scheduler)
11. [Perintah Artisan](#perintah-artisan)
12. [Aturan "Sama Persis"](#aturan-sama-persis)
13. [Keamanan](#keamanan)
14. [User Read-Only untuk PG B](#user-read-only-untuk-pg-b)
15. [Deploy](#deploy)
16. [Pengujian](#pengujian)
17. [Checklist Serah Terima ke IT](#checklist-serah-terima-ke-it)
18. [Batasan yang Diketahui](#batasan-yang-diketahui)

---

## Peran & Pembagian Tanggung Jawab

| Peran | Lingkungan | Driver | Query | Pengujian | Kredensial |
| --- | --- | --- | --- | --- | --- |
| **Developer** | Laptop, tanpa akses jaringan ke Oracle | `mock` + fixture | `SELECT` dummy terhadap fixture | Feature test otomatis (`php artisan test`) | Tidak pernah ada di laptop |
| **IT / DBA** | Server, di dalam jaringan Oracle | `oracle` | `SELECT` terhadap Production | Verifikasi manual memakai tombol Preview dan panel Riwayat | Dikelola DBA, akun `SELECT`-only |

Pembagiannya disengaja. Developer membangun dan menguji seluruh alur — preview,
simpan ke PG A, copy ke PG B, verifikasi jumlah baris dan hash — **tanpa pernah
menyentuh Oracle**. Yang berpindah ke tangan IT hanyalah tiga hal: kredensial
nyata, driver `oracle`, dan konfirmasi lewat Preview.

Konsekuensinya: test yang lolos di laptop tidak menjamin identik 100% dengan
hasil di server, karena `mock` berjalan di atas SQLite, bukan Oracle. Bagian
[Batasan yang Diketahui](#batasan-yang-diketahui) menjelaskan ruang bedanya.

---

## Peringatan APP_KEY

> ## JANGAN PERNAH MENGGANTI ATAU MENGHAPUS `APP_KEY`
>
> Password koneksi Oracle dan PostgreSQL **disimpan terenkripsi di database
> metadata** dengan kunci `APP_KEY` milik Laravel (cast `encrypted` pada kolom
> `password` dan `readonly_password` di tabel `connections` dan `targets`).
>
> **Kalau `APP_KEY` berubah atau hilang, SEMUA password koneksi menjadi tidak
> terbaca** — bukan hanya satu, tapi seluruh Connection dan Target yang pernah
> diisi. Tidak ada pemulihan. Setiap Target dan Connection harus diisi ulang
> dari nol.
>
> Praktisnya: simpan `APP_KEY` di password manager atau secret manager, backup
> bersama database metadata, dan **jangan pernah** menjalankan `key:generate`
> pada instalasi yang sudah berisi data.

Membuat `APP_KEY` **hanya** pada instalasi baru yang belum berisi Connection
maupun Target:

```bash
php artisan key:generate
```

> ### PERINGATAN KERAS
>
> `php artisan key:generate` menimpa `APP_KEY` yang ada di `.env`.
>
> **Jalankan perintah ini HANYA pada instalasi baru.** Menjalankannya pada
> server yang sudah berisi data akan **membatalkan seluruh password tersimpan**
> dan memaksa semua Connection dan Target diisi ulang. Tidak ada perintah
> pemulihan; tidak ada cara memperbaikinya tanpa kredensial asli.

Backup `APP_KEY` bersama dump database metadata:

```bash
php artisan migrate --force
pg_dump "$DB_DATABASE" > backup-ods-meta.sql
```

Kunci dan datanya harus dipulihkan **bersamaan**. `APP_KEY` tanpa database
metadata tidak berguna, dan sebaliknya.

---

## Prasyarat

| Komponen | Kebutuhan | Keterangan |
| --- | --- | --- |
| PHP | **8.4** | `composer.json` menulis `"php": "^8.4"`, jadi Composer menolak instalasi di bawah 8.4 |
| Composer | 2.x | — |
| Node.js + npm | Versi yang didukung Vite 6 | Untuk `npm run build` |
| PostgreSQL | **14+** | Tiga peran: metadata, PG A, PG B |

Ekstensi PHP yang wajib aktif:

| Ekstensi | Untuk apa |
| --- | --- |
| `oci8` | Membaca dari Oracle Production. Tanpa ini hanya driver `mock` yang bisa jalan |
| `pdo_pgsql` | Koneksi ke semua PostgreSQL |
| `pgsql` | Fungsi bawaan PostgreSQL |
| `mbstring` | Operasi string multibyte (`mb_substr` untuk sanitasi pesan error) |
| `intl` | Fungsi locale dan internasional |
| `bcmath` | Presisi aritmetika |
| `zip` | Ekstraksi paket Composer |
| `openssl` | Enkripsi `APP_KEY` dan koneksi |

Verifikasi cepat:

```bash
php -m
php -v
php artisan about
```

Role database yang perlu disiapkan:

- **Metadata** — tempat tabel `settings`, `users`, `connections`, `targets`,
  `datasets`, `dataset_versions`, dan `runs` berada. **Harus terpisah** dari
  PG A dan PG B.
- **PG A** — mirror kanonik, hanya boleh ada satu (dijamin partial unique index
  `targets_single_pg_a` pada tabel `targets`).
- **PG B** — satu database per aplikasi pemakai; boleh lebih dari satu.

---

## Instalasi `oci8` di Windows

Ekstensi `oci8` membutuhkan **Oracle Instant Client**. Mengaktifkan `oci8` saja
pada Windows tidak cukup — ia butuh berkas Instant Client.

### 1. Unduh Oracle Instant Client

Ambil **Basic Package untuk Windows x64** dari halaman resmi Oracle:

<https://www.oracle.com/database/technologies/instant-client/downloads.html>

Unduh paket untuk platform Anda (Windows 64-bit), lalu ekstrak ke lokasi tetap.
Contoh:

```text
C:\oracle\instantclient
```

Pastikan berkas berikut ada di folder tersebut:

```text
C:\oracle\instantclient\oci.dll
C:\oracle\instantclient\ociw32.dll
```

### 2. Tambahkan ke `PATH`

Tambahkan `C:\oracle\instantclient` ke variabel lingkungan `Path`, lalu tutup
dan buka ulang terminal agar perubahan terbaca.

### 3. Aktifkan `oci8` di `php.ini`

Buka `php.ini` yang dipakai PHP CLI Anda, lalu tambahkan:

```ini
extension_dir = "D:\php8.4\ext"
extension=oci8
```

Sesuaikan `extension_dir` dengan lokasi ekstensi PHP 8.4 Anda.

> **Catatan `PHPRC`.** Kalau lebih dari satu instalasi PHP ada di mesin ini,
> `php --ini` menunjukkan berkas yang benar-benar dipakai. Tambahkan baris di atas
> ke berkas itu, bukan ke `php.ini` milik instalasi lain. Bila `PHPRC`
> diarahkan ke folder khusus, nilai `extension_dir` pada berkas tersebut
> menimpa nilai bawaan — jadi tulisannya harus lengkap, bukan hanya
> `extension=oci8`.

### 4. Verifikasi

```bash
php -m | findstr oci8
```

Kalau baris `oci8` muncul, ekstensi sudah aktif.

### 5. Restart proses yang memuat PHP

Setiap proses yang sudah berjalan memuat `php.ini` **sekali** di awal. Setelah
mengubah `php.ini`, restart:

- web server (Apache / Nginx + PHP-FPM / IIS),
- **queue worker** (`queue:work`) — kalau tidak, worker masih memakai konfigurasi lama,
- scheduler (`schedule:work`) bila sedang berjalan.

---

## Instalasi `oci8` di Linux (Ubuntu/Debian)

Di Linux, paket Oracle Instant Client bisa dipasang lewat repository resmi
Oracle, lalu paket `php8.4-oci8` diambil dari sumber paket tersebut.

### 1. Tambahkan repository Oracle Instant Client

Buat berkas daftar paket:

```bash
sudo tee /etc/apt/sources.list.d/oracle-instant-client.list > /dev/null <<'EOF'
deb https://yum.oracle.com/ASOracleLinux-8/oracle-instantclient/x86_64/release-23 el8 $
EOF
```

Lalu segarkan daftar paket:

```bash
sudo apt-get update
```

### 2. Pasang Instant Client

```bash
sudo apt-get install -y oracle-instantclient-basic
```

Paket ini memasang Instant Client dan membuat symlink ke
`/usr/lib/oracle/23/client64/lib`, folder yang dicari `oci8`.

### 3. Definisikan `ORACLE_HOME` dan `LD_LIBRARY_PATH`

```bash
sudo tee /etc/profile.d/oracle-client.sh > /dev/null <<'EOF'
export ORACLE_HOME=/usr/lib/oracle/23/client64
export LD_LIBRARY_PATH="$ORACLE_HOME/lib:$LD_LIBRARY_PATH"
export PATH="$ORACLE_HOME/bin:$PATH"
EOF
source /etc/profile.d/oracle-client.sh
```

Agar permanen untuk layanan, tambahkan dua variabel yang sama ke unit systemd
atau ke pool `php-fpm` yang menjalankan aplikasi.

### 4. Pasang ekstensi PHP

```bash
sudo apt-get install -y php8.4-oci8
```

> **Nama paket `php8.4-oci8` dipakai sebagai contoh.** Paket `oci8` bukan bagian
> dari repositori bawaan Ubuntu/Debian; ia berasal dari repository Oracle atau
> PPA pihak ketiga yang Anda pilih. Sesuaikan nama paket dengan sumber paket
> yang dipakai di lingkungan Anda.

### 5. Restart PHP-FPM dan worker

```bash
sudo systemctl restart php8.4-fpm
sudo supervisorctl restart ods-worker ods-scheduler
```

### 6. Verifikasi

```bash
php -m | grep oci8
```

---

## Instalasi Aplikasi

### Di server (punya ekstensi `oci8`)

```bash
composer install
npm install
npm run build
```

### Di mesin pengembangan (belum punya ekstensi `oci8`)

Paket `yajra/laravel-oci8` menyatakan `ext-oci8` sebagai syarat, padahal di
laptop developer kita **sengaja tidak** memasang `oci8` — development memakai
driver `mock`. Supaya `composer install` tidak gagal di sana, tambahkan:

```bash
composer install --ignore-platform-req=ext-oci8
```

Opsi ini hanya melewati pemeriksaan platform **saat instal**. Kode driver
`oracle` tetap ada dan tetap tidak bisa dipakai tanpa `oci8`; kalau tidak ada
ekstensinya, memakai driver `oracle` akan gagal saat koneksi — bukan diam-diam
berhasil.

Lanjut sama untuk langkah berikutnya:

```bash
npm install
npm run build
```

Salin berkas contoh konfigurasi, buat kunci aplikasi, lalu jalankan migrasi:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

> Ingat peringatan [APP_KEY](#peringatan-app_key): `key:generate` di atas aman
> **hanya** karena `.env` masih kosong pada langkah ini.

Isi konfigurasi database **metadata** di `.env`. Nilai ini **harus berbeda** dari
PG A dan PG B:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ods_meta
DB_USERNAME=postgres
DB_PASSWORD=password-metadata
```

Opsi lain yang relevan:

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database

# Lock sinkronisasi WAJIB berasal dari store yang SHARED antar worker.
# Untuk produksi multi-host, ganti ke redis.
SYNC_LOCK_STORE=database

# Fixture untuk driver mock. Kosongkan untuk memakai
# storage/app/fixtures/{nama-set}.json
SYNC_FIXTURE_PATH=
```

Bersihkan cache konfigurasi setelah mengubah `.env`:

```bash
php artisan config:clear
```

---

## Menjalankan Lokal dengan Mode MOCK

Driver `mock` membaca dari fixture JSON, bukan dari Oracle. Developer lokal tidak
perlu `oci8`, tidak perlu jaringan ke Oracle, dan tidak pernah melihat kredensial
produksi.

> **Panel sudah tersedia.** Aplikasi memakai Inertia + Vue 3 + Tailwind. Halaman
> yang ada: Dashboard, Dataset, SQL Editor (Preview & Simpan), Copy A → B,
> Riwayat, Pause, dan Driver (khusus admin).
>
> Semua langkah di bawah bisa dilakukan lewat browser di `http://127.0.0.1:8000`.
> Perintah `tinker` tetap disertakan sebagai padanan untuk server yang tidak
> menjalankan Vite.

### 1. Jalankan server

```bash
composer serve
```

Buka `http://127.0.0.1:8000`. Untuk pengembangan dengan hot reload:

```bash
npm run dev
```

> **`php artisan serve` gagal di Windows.** Gejalanya:
>
> ```text
> Failed to listen on 127.0.0.1:8000 (reason: ?)
> Failed to listen on 127.0.0.1:8001 (reason: ?)
> ```
>
> Penyebabnya bukan port yang sedang dipakai. Laravel 12 meneruskan environment
> ke server bawaan PHP memakai daftar putih `ServeCommand::$passthroughVariables`
> yang berisi `PATH` dan `SYSTEMROOT` huruf besar, sedangkan `$_ENV` Windows
> menuliskan `Path` dan `SystemRoot`. Pencocokan `in_array()` bersifat
> case-sensitive, jadi kedua variabel itu justru **dihapus** dari child process.
> Tanpa `SystemRoot`/`ComSpec`, Winsock tidak bisa diinisialisasi dan `php -S`
> gagal bind port.
>
> `composer serve` memanggil `php -S` langsung tanpa memanipulasi environment,
> jadi tidak terkena masalah ini. Perintah yang setara:
>
> ```bash
> php -S 127.0.0.1:8000 server.php
> ```
>
> Di Linux `php artisan serve` tidak bermasalah, dan `composer serve` tetap aman
> dipakai di kedua platform.

### 2. Buat user admin pertama

Tidak ada pendaftaran mandiri, jadi buat lewat `tinker`:

```bash
php artisan tinker
```

```php
App\Models\User::create([
    'name'     => 'Admin ODS',
    'email'    => 'admin@example.com',
    'password' => 'password-yang-kuat',
    'role'     => App\Enums\UserRoleEnum::Admin,
]);
```

Kolom `password` memakai cast `hashed`, jadi isikan plaintext apa adanya — Laravel
yang menghash-nya. Peran yang tersedia hanya `admin` dan `viewer`
(`app/Enums/UserRoleEnum.php`); `canManage()` hanya berlaku untuk `admin`.

### 3. Buat Connection dengan driver `mock` (halaman Driver, atau `tinker`)

Di browser: buka **Driver → Tambah koneksi**, pilih driver `Mock`, isi katalog
fixture `armors`, lalu tekan **Tes Koneksi**.

```php
App\Models\Connection::create([
    'name'        => 'mock-armors',
    'driver'      => App\Enums\DriverEnum::Mock,
    'fixture_set' => 'armors',
    'username'    => 'mock_reader',
    'password'    => 'mock-secret',
    'is_active'   => true,
]);
```

`fixture_set` boleh dikosongkan; nilainya akan jatuh ke
`config('oracle-sync.fixtures.default_set')` yang bernilai `armors`. Kolom
`password` tetap terenkripsi meski tidak pernah dipakai driver mock.

Uji koneksinya lewat UI, atau lewat CLI:

```bash
php artisan oracle-sync:test-connection mock-armors
php artisan oracle-sync:test-connection mock-armors --json
```

### 4. Buat Target PG A dan PG B (halaman Driver, atau `tinker`)

Di browser: **Driver → Tambah target**, pilih peran `PostgreSQL A` (hanya boleh
ada satu) atau `PostgreSQL B` (boleh banyak, wajib mengisi nama aplikasi).
Tekan **Tes Koneksi** setelah mengisi.

```php
// PG A - kanonik. Hanya boleh ada satu.
$pgA = App\Models\Target::create([
    'name'      => 'PG A lokal',
    'kind'      => App\Enums\TargetKindEnum::PgA,
    'host'      => '127.0.0.1',
    'port'      => 5432,
    'database'  => 'ods_pg_a',
    'username'  => 'postgres',
    'password'  => 'postgres',
    'sslmode'   => 'prefer',
    'is_active' => true,
]);

// PG B - salinan untuk satu aplikasi.
$pgB = App\Models\Target::create([
    'name'      => 'PG B armors',
    'kind'      => App\Enums\TargetKindEnum::PgB,
    'app_name'  => 'armors',
    'host'      => '127.0.0.1',
    'port'      => 5432,
    'database'  => 'ods_pg_b_armors',
    'username'  => 'postgres',
    'password'  => 'postgres',
    'sslmode'   => 'prefer',
    'is_active' => true,
]);
```

Target `pg_b` **wajib** mengisi `app_name` — dicek oleh constraint
`targets_pg_b_needs_app`. Sementara itu, pembuatan PG A kedua ditolak oleh partial
unique index `targets_single_pg_a`, dan panel juga menolak lebih dulu dengan
pesan yang jelas.

### 5. Buat Dataset lewat SQL Editor

Buka **Dataset → Dataset baru**, lalu:

1. Pilih koneksi sumber dan ketik satu query `SELECT`.
2. Tekan **Preview**. Panel menampilkan nama kolom, tipe Oracle, tipe PostgreSQL
   hasil pemetaan, peringatan tipe, dan 20 baris data. **Tidak ada yang disimpan.**
3. Centol kolom kunci bila memilih mode incremental.
4. Centol target PG B bila ingin copy otomatis tiap sinkronisasi sukses.
5. Tekan **Simpan ke PostgreSQL A**.

Nama schema dan tabel tujuan **tidak perlu diketik** untuk query yang membaca satu
tabel Oracle: panel mengambilnya dari metadata Oracle. Untuk query JOIN, agregasi,
atau ber-alias, panel mewajibkan Anda mengisi nama tabel tujuan sendiri dan
memberi peringatan per kolom.

Alur yang sama tanpa browser:

```php
$created = app(App\Services\Sync\DatasetManager::class)->create([
    'name'            => 'po-headers',
    'connection_id'   => App\Models\Connection::where('name', 'mock-armors')->value('id'),
    'target_id'       => $pgA->id,
    'sql'             => 'SELECT PO_HEADER_ID, PO_NUMBER, VENDOR_ID, STATUS_CODE, ORDER_DATE, LAST_UPDATE_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
    'sync_mode'       => App\Enums\SyncModeEnum::Full,
    'timeout_seconds' => 300,
    'max_rows'        => 1000000,
    'batch_size'      => 2000,
    'auto_copy_to'    => [$pgB->id],
], null);
```

`target_id` **selalu** PG A. Copy ke PG B berjalan terpisah dan dipicu oleh kolom
`auto_copy_to` setelah sync benar-benar sukses. Unique index
`datasets_destination_unique` pada `(target_id, destination_schema,
destination_table)` menjadikan aturan "satu tabel tujuan = satu dataset"
ditegakkan di level database, bukan hanya di kode.

### 6. Jalankan sinkronisasi pertama

Di browser: buka dataset tersebut, tekan **Jalankan sinkronisasi**, lalu periksa
badge verifikasi pada halaman **Riwayat**.

Lewat CLI:

```bash
php artisan oracle-sync:sync po-headers
```

Keluaran perintah memuat status, jumlah baris, dan badge verifikasi:

```text
[success] dataset=po-headers rows=5 verifikasi=ok
```

Lihat status yang tersimpan untuk aplikasi:

```sql
SELECT last_success_at, row_count
FROM _sync.status
WHERE target_kind = 'pg_a';
```

### Ringkasan halaman panel

| Halaman | Isi | Siapa |
| --- | --- | --- |
| Dashboard | Ringkasan dataset, eksekusi terakhir, status kill switch | semua |
| Dataset | Daftar dataset, status, jadwal, peringatan incremental | semua |
| Dataset Editor | SQL Editor CodeMirror, Preview, Simpan, Jalankan, riwayat versi | semua; hanya admin boleh mengubah |
| Copy A → B | Pilih tabel dari A dan target B, tekan Copy | semua |
| Riwayat | Semua eksekusi + filter, detail hash per run | semua |
| Pause | Kill switch global | semua |
| Driver | Koneksi sumber & target PG, tombol Tes Koneksi | **hanya admin** |

---

## Driver `mock`

Driver `mock` memuat fixture JSON ke dalam database **SQLite in-memory**,
menerjemahkan query `SELECT` dari dialek Oracle ke dialek SQLite, lalu
menjalankannya. `MockOracleReader` mengimplementasikan interface `SourceReader`
yang sama persis dengan `OracleReader`, jadi alur Preview, Simpan ke A, Copy ke B,
dan verifikasi berjalan lewat kode yang identik. Yang berbeda hanya sumber
datanya.

### Fixture bawaan

Fixture `armors` (`storage/app/fixtures/armors.json`) berisi 11 tabel di schema
`APPS`: `PO_HEADERS`, `PO_LINES`, `ITEMS`, `ITEM_UOM`, `STOCK_LOCATORS`,
`SUPPLIERS`, `RECEIPT_HEADERS`, `RECEIPT_LINES`, `ONHAND_BALANCE`,
`REORDER_POINTS`, dan `EDGE_CASE_TYPES`. Masing-masing membawa metadata kolom
gaya Oracle — `NUMBER` beserta presisi dan skalanya, `VARCHAR2` dan `NVARCHAR2`
beserta panjang karakter, `DATE`, `TIMESTAMP`, `CLOB`, `BLOB` (base64), serta
`BINARY_DOUBLE` — sehingga pemetaan tipe bisa diuji dengan kasus nyata, bukan
dengan tebakan.

`sysdate` pada fixture bernilai tetap (`2026-03-15 09:00:00`), bukan jam sistem,
supaya hasil test deterministik dan bisa diulang.

### Menambah fixture sendiri

Letakkan berkas JSON di `storage/app/fixtures/` dengan nama berkas sama dengan
nama set. Nilai itulah yang diisi ke kolom `fixture_set` pada Connection.

```json
{
  "set": "gudang",
  "default_schema": "APPS",
  "sysdate": "2026-03-15 09:00:00",
  "tables": [
    {
      "schema": "APPS",
      "name": "WAREHOUSES",
      "columns": [
        { "name": "WAREHOUSE_ID", "oracle_type": "NUMBER", "data_precision": 12, "data_scale": 0, "position": 1 },
        { "name": "WAREHOUSE_NAME", "oracle_type": "VARCHAR2", "char_length": 60, "char_used": 2, "position": 2 },
        { "name": "THUMBNAIL", "oracle_type": "BLOB", "encoding": "base64", "position": 3 }
      ],
      "rows": [
        { "WAREHOUSE_ID": "1", "WAREHOUSE_NAME": "Gudang Utama", "THUMBNAIL": null },
        { "WAREHOUSE_ID": "2", "WAREHOUSE_NAME": "Gudang Cadangan", "THUMBNAIL": "aW1hZ2U=" }
      ]
    }
  ]
}
```

Kuncinya: `tables` wajib ada. Kolom biner ditulis sebagai base64 dan diberi
`"encoding": "base64"`. Nilai baris boleh berupa string numerik seperti
`"100.0000"` — begitulah cara presisi `NUMBER(p,s)` dipertahankan di sumber.

Lokasi fixture juga bisa diarahkan ke luar folder bawaan lewat
`SYNC_FIXTURE_PATH` di `.env`, atau diisi dengan path absolut langsung pada
`fixture_set`.

### Batasan mock — baca ini sebelum menyimpulkan apa pun

Mock **bukan pengganti Oracle dan tidak dimaksudkan menjadi emulator Oracle**.
Ia adalah sumber data substitusi untuk pengembangan dan test. Batasannya:

| Aspek | Kenyataannya |
| --- | --- |
| Mesin SQL | Query dijalankan di atas **SQLite in-memory**, bukan Oracle |
| Presisi angka | SQLite menyimpan angka sebagai IEEE-754, jadi hanya sekitar **15 digit signifikan** yang terjaga |
| Metadata kolom | Pada mode identitas, nama dan tipe kolom diambil dari **metadata fixture**, bukan dari tebakan isi data |

**Didukung:**

| Konstruksi Oracle | Diterjemahkan menjadi |
| --- | --- |
| `SYSDATE`, `SYSTIMESTAMP`, `CURRENT_DATE`, `CURRENT_TIMESTAMP` | Waktu tetap dari fixture (`sysdate`) |
| `ROWNUM <= n` | `LIMIT n` |
| `FETCH FIRST n ROWS ONLY`, `FETCH NEXT n ROWS ONLY` | `LIMIT n` |
| `NVL(a, b)` | `IFNULL(a, b)` |
| `TO_CHAR(<tanggal>, 'YYYY-MM-DD')` | `STRFTIME('%Y-%m-%d', <tanggal>)` |
| `TRUNC(<tanggal>)` | `DATE(<tanggal>)` |
| `FROM DUAL` | Tabel `DUAL` yang dibuat reader |

**Tidak didukung:**

| Konstruksi | Catatan |
| --- | --- |
| `CONNECT BY` | Hierarki tidak bisa ditiru |
| `MODEL` | — |
| `PIVOT` / `UNPIVOT` | — |
| `MATCH_RECOGNIZE` | — |
| `DECODE` | — |
| `ADD_MONTHS` | — |
| `MONTHS_BETWEEN` | — |
| Paket `DBMS_*` | Juga ditolak lebih awal oleh `SelectGuard` |
| Hint optimizer Oracle | — |

Format `TO_CHAR` yang tidak dikenali **dibiarkan apa adanya** supaya error
terlihat jelas, bukan menghasilkan data yang salah diam-diam.

> **`OCI8.LOAD_HYDRATE_COLUMNS` tidak dipakai.** Driver mock tidak menyalakan
> `HydrateColumn`, sehingga nilai kolom pada driver `mock` **selalu dibaca sebagai
> teks**. Ini konsekuensi yang disengaja: supaya nilai sampai ke lapisan
> kanonikalisasi dalam bentuk yang stabil. Presisi `NUMBER` yang lebih panjang
> diuji langsung lewat `Canonicalizer`, bukan lewat fixture.

---

## Mode mock vs Mode Oracle

| Tahap | Yang dikerjakan developer | Yang dikerjakan IT |
| --- | --- | --- |
| **Driver** | `mock` + fixture JSON | `oracle` + kredensial asli |
| **Query** | `SELECT` dummy terhadap tabel fixture | `SELECT` terhadap tabel Production |
| **Pengujian** | Feature test otomatis (`php artisan test`) | Tes manual lewat Preview dan panel Riwayat |
| **Kredensial** | Tidak pernah ada di laptop dev | Dikelola DBA, akun `SELECT`-only |

Baris "Kredensial" adalah pembeda paling penting: developer bekerja tanpa secret
apa pun, dan secret tidak pernah masuk ke repositori.

---

## Queue Worker & Scheduler

Keduanya **wajib berjalan terus** di server. Tanpa worker, job yang dijadwalkan
akan menumpuk di tabel `jobs` dan tidak pernah dieksekusi.

Untuk pengembangan:

```bash
php artisan queue:work --queue=sync --tries=1 --timeout=3600 --sleep=3
php artisan schedule:work
```

Penjelasan flag:

| Flag | Alasan |
| --- | --- |
| `--queue=sync` | Hanya antrean `sync` yang ditangani worker ini. Antrean default tidak tersentuh |
| `--tries=1` | `SyncDatasetJob` sudah menetapkan `public int $tries = 1`. Kegagalan tidak diulang otomatis; run berikutnya yang akan mencoba lagi, dan tiap percobaan tercatat di tabel `runs` |
| `--timeout=3600` | Selaras dengan `public int $timeout = 3600` pada job |
| `--sleep=3` | Jeda 3 detik saat antrean kosong, agar CPU worker tidak berputar tanpa henti |

Scheduler punya dua tugas (`routes/console.php`):

- `sync-datasets` — dijalankan **setiap menit**, memeriksa setiap dataset
  berstatus `active` yang cron-nya cocok, lalu menaruh job ke antrean `sync`.
  Cron default tiap dataset adalah `0 * * * *` (setiap jam).
- `reconcile-full` — menjalankan `oracle-sync:reconcile` pada cron default
  `0 2 * * *` (setiap malam pukul 02:00).

### Produksi: supervisor

`schedule:work` berjalan terus-menerus dan sangat cocok untuk supervisor. Buat
berkas `/etc/supervisor/conf.d/ods.conf`:

```ini
[program:ods-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/oracle-data-sync/artisan queue:work --queue=sync --tries=1 --timeout=3600 --sleep=3
directory=/var/www/oracle-data-sync
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ods-worker.log
stdout_logfile_maxbytes=10MB
stopwaitsecs=3700

[program:ods-scheduler]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/oracle-data-sync/artisan schedule:work
directory=/var/www/oracle-data-sync
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ods-scheduler.log
stdout_logfile_maxbytes=10MB
```

> `stopwaitsecs=3700` sengaja lebih besar dari `--timeout=3600`, supaya
> supervisor tidak mengirim `SIGKILL` sebelum worker sempat menutup koneksi
> dengan rapi.

Aktifkan dan jalankan:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
sudo supervisorctl restart ods-worker ods-scheduler
```

### Alternatif: `schedule:run` lewat cron

Kalau `ods-scheduler` tidak dipakai, jalankan `schedule:run` dari cron sistem
setiap menit:

```cron
* * * * * cd /var/www/oracle-data-sync && php artisan schedule:run >> /dev/null 2>&1
```

Hindari menjalankan **keduanya** bersamaan — setiap tick akan diproses dua kali.
Pilih salah satu: supervisor `ods-scheduler` **atau** cron `schedule:run`.

### Windows

`queue:work` membutuhkan ekstensi `pcntl`, yang hanya tersedia di Linux. Di
Windows worker tidak bisa berjalan; sinkronisasi harus dipicu lewat perintah
`oracle-sync:sync` secara manual, atau tool harus dijalankan di server Linux.

---

## Perintah Artisan

| Perintah | Fungsi |
| --- | --- |
| `oracle-sync:test-connection` | Menguji koneksi sumber data tanpa menyimpan apa pun |
| `oracle-sync:sync` | Menjalankan sinkronisasi Oracle ke PostgreSQL A |
| `oracle-sync:reconcile` | Menjalankan sinkronisasi `full` untuk semua dataset `incremental` |
| `oracle-sync:pause` | Kill switch global: pause dan resume semua job |

### `oracle-sync:test-connection`

```bash
php artisan oracle-sync:test-connection mock-armors
php artisan oracle-sync:test-connection 1 --json
```

Argumen boleh berupa nama koneksi atau ID-nya. Perintah menjalankan query
teringan (`SELECT 1 FROM DUAL`) lalu menyimpan hasil ke `last_tested_at`,
`last_test_status`, dan `last_test_message`. Password tidak pernah dicetak dan
tidak pernah masuk log.

### `oracle-sync:sync`

```bash
php artisan oracle-sync:sync po-headers           # satu dataset, by nama atau ID
php artisan oracle-sync:sync --all                # semua dataset aktif
php artisan oracle-sync:sync 3 --trigger=manual
php artisan oracle-sync:sync po-headers --full    # paksa mode full
```

Tanpa argumen dan tanpa `--all`, perintah gagal dengan pesan
`Sebutkan dataset, atau gunakan --all.` alih-alih menjalankan semuanya. Opsi
`--full` memakai kind `reconcile` sehingga bisa dibedakan di riwayat.

### `oracle-sync:reconcile`

```bash
php artisan oracle-sync:reconcile --dry-run
php artisan oracle-sync:reconcile
```

Perintah ini hanya menyentuh dataset berstatus `active` **dan**
`sync_mode = incremental`, karena hanya mode `full` yang menjamin hasil identik
termasuk baris yang dihapus di Oracle.

> `--dry-run` mencetak daftar dataset yang akan direkonsiliasi beserta tabel
> tujuannya, lalu berhenti tanpa menjalankan apa pun:

```text
- po-headers -> "APPS"."PO_HEADERS"
- po-lines -> "APPS"."PO_LINES"
```

Gunakan `--dry-run` sebelum menjalankan rekonsiliasi sungguhan pada dataset
besar — Anda bisa melihat persis tabel mana yang akan ditulis ulang tanpa
menanggung dampaknya.

### `oracle-sync:pause`

```bash
php artisan oracle-sync:pause --reason="Investigasi anomali di PG A" --user=1
php artisan oracle-sync:pause --resume
```

Menjadikan kill switch aktif: seluruh job berhenti dan tidak ada penulisan ke
PG A maupun PG B. Statusnya disimpan di tabel `settings` — bukan di cache —
supaya konsisten antar beberapa worker dan bertahan setelah restart.

---

## Aturan "Sama Persis"

Enam aturan ini adalah kontrak tool. Kalau tidak bisa dijamin, sinkronisasi
tidak dijalankan.

### 1. Nama harus identik dengan metadata Oracle

Nama schema, tabel, dan kolom diambil dari metadata Oracle, ditulis huruf
besar, dan selalu di-*quote* sebagai identifier PostgreSQL. Hasilnya
`"APPS"."PO_HEADERS"` dengan kolom `"PO_HEADER_ID"` — bukan `po_headers` dan
bukan `poheaders`. Mode ini diaktifkan lewat `identity_mode = true` pada
dataset, dan nilainya di-*resolve* dari metadata Oracle saat dataset dibuat.

### 2. JOIN dan agregasi wajib punya nama tujuan sendiri

Query dengan JOIN, agregasi, atau alias tidak bisa mengikuti aturan 1 karena
hasilnya bukan tabel Oracle. Untuk query seperti itu, `identity_mode = false`
dan Anda **wajib** memberi `destination_schema` dan `destination_table` sendiri.
Tabel tujuan yang sama hanya boleh dimiliki **satu** dataset — dijamin unique
index `datasets_destination_unique` pada
`(target_id, destination_schema, destination_table)`.

### 3. Tipe dipetakan dengan menjaga presisi

Pemetaannya diimplementasikan di `app/Services/Types/OracleTypeMapper.php`. Tipe
yang tidak dikenal menjadi `text` **disertai peringatan**, bukan ditebak
diam-diam.

| Tipe Oracle | Tipe PostgreSQL |
| --- | --- |
| `NUMBER(p,s)` | `numeric(p,s)` |
| `NUMBER` tanpa presisi | `numeric` + peringatan |
| `NUMBER(p,-s)` skala negatif | `numeric(p)` + peringatan |
| `BINARY_FLOAT` | `real` + peringatan (hanya sekitar 7 digit signifikan) |
| `BINARY_DOUBLE` | `double precision` |
| `VARCHAR2(n)` / `NVARCHAR2(n)` | `varchar(n)` |
| `VARCHAR2` panjang di atas 4000 atau tak terbaca | `text` + peringatan |
| `CHAR(n)` / `NCHAR(n)` | `char(n)` |
| `CLOB`, `NCLOB`, `LONG`, `LONG VARCHAR` | `text` |
| `BLOB`, `RAW`, `LONG RAW` | `bytea` |
| **`DATE`** | **`timestamp(0)`** |
| `TIMESTAMP(p)` | `timestamp(p)` |
| `TIMESTAMP WITH TIME ZONE` | `timestamptz` |
| `TIMESTAMP WITH LOCAL TIME ZONE` | `timestamptz` + peringatan |
| `INTERVAL DAY TO SECOND` | `interval` |
| `INTERVAL YEAR TO MONTH` | `interval` + peringatan |
| `BOOLEAN` | `boolean` + peringatan (butuh Oracle 23c ke atas) |
| `JSON`, `ROWID`, `UROWID`, `XMLTYPE` | `text` + peringatan |
| Tipe lain (`SDO_*`, `MDSYS.*`, UDT, dan sejenisnya) | `text` + peringatan |

> **PENTING: Oracle `DATE` dipetakan ke `timestamp(0)`, bukan ke `date`.**
>
> `DATE` di Oracle bukan tanggal saja — isinya tahun, bulan, hari, **jam, menit,
> dan detik**. Memetakannya ke `date` PostgreSQL akan membuang jamnya. Karena itu
> tool selalu memetakannya ke `timestamp(0)`, dan setiap kolom semacam itu disertai
> peringatan di hasil Preview.

### 4. Nilai disalin apa adanya

Tidak ada `trim`, tidak ada pembulatan, tidak ada konversi zona waktu
tersembunyi. Sesi Oracle dipaksa ke allowlist literal milik tool —
`TIME_ZONE = '+00:00'`, `NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'`, dan format
NLS untuk timestamp — sedangkan sesi PostgreSQL dipaksa ke `timezone = 'UTC'`
dengan `extra_float_digits = 3`. Hasil baca jadi sebanding dan stabil antar
eksekusi. `NULL` tetap `NULL`.

### 5. Verifikasi lewat jumlah baris dan hash isi bebas-urutan

Setiap run membandingkan jumlah baris dan hash konten antara sisi sumber dan sisi
target. Hash dihitung secara streaming dari baris yang sedang dibaca — tanpa query
tambahan ke Oracle — dan bersifat bebas-urutan karena Oracle tidak menjamin
urutan baris tanpa `ORDER BY`. Rangkaiannya: digest SHA-256 per baris,
digabungkan lewat tiga agregat bebas-urutan (`count`, `xor`, `sum`), lalu digest
akhir `sha256(count || xor || sum)`. Pasangan `xor` + `sum` + `count` menutup celah
dua baris identik yang saling meniadakan di XOR saja.

Batas baris juga ditegakkan: tool membaca satu baris lebih banyak dari yang
diizinkan. Kalau ternyata ada, sinkronisasi **gagal dengan jelas** — memotong
diam-diam akan menghasilkan tabel tidak lengkap yang verifikasinya tetap hijau.

### 6. Tabel data tidak pernah diberi kolom teknis

Tidak ada kolom `_id`, `_synced_at`, atau sejenisnya yang disisipkan ke tabel data.
Semua status disimpan di schema terpisah `_sync` pada masing-masing PG A dan
PG B, berisi tabel `status`, `watermarks`, dan `runs`. Aplikasi cukup membaca
`_sync.status` untuk menampilkan "data terakhir diperbarui pukul ..." tanpa
menyentuh tabel data sama sekali. Kunci baris `status` adalah
`(target_kind, app_name, dataset_key)`, sehingga PG A dan beberapa PG B bisa
memakai nama tabel yang sama tanpa saling menimpa.

---

## Keamanan

1. **Tool tidak pernah menulis ke Oracle.** Seluruh akses ke Oracle melewati satu
   kelas, dan kelas itu hanya menerima `SELECT`. Tidak ada kode INSERT, UPDATE,
   DELETE, MERGE, DDL, atau PL/SQL ke arah Oracle di mana pun.

2. **User Oracle WAJIB `SELECT`-only yang dibuat DBA — inilah pengaman utama.**
   Validasi di dalam aplikasi (`SelectGuard`) hanyalah **lapis kedua** untuk
   menangkap kesalahan penulisan user, bukan untuk menahan serangan. Pengaman
   yang sesungguhnya adalah hak akses di sisi database.

   Perintah SQL untuk membuat user tersebut — dijalankan manual oleh DBA:

   ```sql
   CREATE USER ods_reader IDENTIFIED BY "ganti-dengan-password-kuat"
     DEFAULT TABLESPACE users
     TEMPORARY TABLESPACE temp
     QUOTA UNLIMITED ON users;

   GRANT CREATE SESSION TO ods_reader;
   GRANT SELECT ON APPS.PO_HEADERS TO ods_reader;
   GRANT SELECT ON APPS.PO_LINES    TO ods_reader;
   ```

   > Tool **tidak pernah** membuat user di Oracle — memang itu bagian dari kontrak
   > "tidak menyentuh Oracle". Perintah di atas wajib dijalankan oleh DBA, dan
   > tool tidak punya konfigurasi apa pun untuk membuatnya otomatis.

3. **Hanya admin yang boleh mengubah driver dan dataset.** Peran `admin` dicek di
   middleware, bukan disembunyikan di view — `viewer` tetap melihat halaman,
   tetapi tidak bisa mengubah apa pun. Setiap perubahan query disimpan sebagai
   versi di tabel `dataset_versions`: siapa, kapan, dan isi sebelum/sesudah.

4. **Batas keras pada setiap eksekusi.** `timeout_seconds` (default 300),
   `max_rows` (default 1.000.000), penulisan per batch (`batch_size`, default
   2.000, tanpa pernah memuat seluruh hasil ke memori), dan **lock per dataset**
   dengan TTL 900 detik supaya satu dataset tidak mungkin berjalan ganda. Job
   juga memakai `WithoutOverlapping` sebagai lapis kedua.

5. **Penulisan staging lalu swap atomik.** Data ditulis ke tabel staging
   berawalan `__stg_`, lalu `DROP TABLE` dan `ALTER TABLE ... RENAME` dijalankan
   dalam **satu transaksi**. Karena DDL PostgreSQL bersifat transaksional, tabel
   lama tidak pernah tersentuh kalau ada kegagalan di tengah. Kalau gagal, tabel
   staging dibersihkan.

6. **Oracle mati tidak membuat aplikasi crash.** Kegagalan koneksi dicatat sebagai
   run berstatus `failed` dengan pesan yang sudah disanitasi — password, DSN, dan
   username dibuang — bukan exception yang merambat ke seluruh aplikasi. PG A dan
   PG B yang sudah terisi tetap utuh.

7. **Tombol "Pause semua job" sebagai kill switch.** Satu tombol menghentikan
   seluruh sinkronisasi dan copy. Statusnya disimpan di tabel `settings`, bukan di
   cache, supaya konsisten antar beberapa worker dan bertahan setelah restart.
   Selama aktif, tidak ada penulisan apa pun ke PG A maupun PG B.

8. **Aplikasi membaca PG B memakai user `read-only`.** Bukan user owner. Yang bisa
   dilakukan aplikasi hanyalah `SELECT`; perintah SQL-nya ada di bagian
   berikutnya.

> **Catatan Tahap 2.** Endpoint refresh untuk aplikasi —
> `POST /api/v1/targets/{target}/datasets/{dataset}/refresh` — beserta kolom
> `runs.api_token_id` dan enum `RunTriggerEnum::Api` sudah disiapkan di skema,
> tetapi **belum diimplementasikan**. Pemicu `api` belum aktif.

### Lapis kedua: `SelectGuard`

`app/Services/Source/Sql/SelectGuard.php` memindai statement sebelum dikirim ke
Oracle. Yang ditolak:

- statement yang tidak **dimulai** dengan `SELECT` atau `WITH` — bukan sekadar
  yang memuat kata itu, sehingga `EXPLAIN PLAN FOR SELECT ...` ikut tertangkap;
- lebih dari satu statement, atau titik koma yang bukan terminator di akhir;
- keyword terlarang (`insert`, `update`, `delete`, `merge`, `drop`, `create`,
  `alter`, `truncate`, `grant`, `revoke`, `commit`, `begin`, `execute`,
  `lock`, `vacuum`, `flashback`, dan lainnya);
- pola berbahaya di luar literal string: `FOR UPDATE`, db link (`@`),
  `EXECUTE IMMEDIATE`, paket `DBMS_*`, paket `UTL_*`, kata `remote`, serta
  `DBMS_PIPE.RECEIVE/SEND`;
- statement lebih panjang dari 100.000 karakter.

Semua alasan penolakan dikumpulkan sekaligus dan ditampilkan dalam pesan yang
jelas, bukan hanya error pertama.

---

## User Read-Only untuk PG B

Aplikasi **tidak boleh** memakai user owner. Ia hanya boleh `SELECT`.

Tool bisa mencoba membuatnya sendiri lewat `ReadOnlyProvisioner`, tapi membuat
role butuh hak admin di PostgreSQL. Kalau ditolak, sinkronisasi tidak gagal —
hanya muncul peringatan yang meminta DBA menjalankan SQL di bawah secara manual.
Karena itu perintah SQL-nya disediakan sebagai jaring pengaman dan **boleh
dijalankan manual kapan saja**.

```sql
CREATE ROLE armors_ro LOGIN PASSWORD 'ganti-dengan-password-kuat' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
GRANT CONNECT ON DATABASE nama_database_b TO armors_ro;
GRANT USAGE ON SCHEMA apps TO armors_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA apps TO armors_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA apps GRANT SELECT ON TABLES TO armors_ro;
```

Penjelasan per baris:

| Perintah | Fungsi |
| --- | --- |
| `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT` | Mencabut hak admin. User ini tidak bisa apa-apa selain membaca |
| `GRANT CONNECT ON DATABASE` | Satu-satunya hak di level database |
| `GRANT USAGE ON SCHEMA apps` | Wajib agar nama objek di dalam schema bisa dirujuk |
| `GRANT SELECT ON ALL TABLES` | Memberi `SELECT` pada tabel yang sudah ada |
| `ALTER DEFAULT PRIVILEGES` | Menutup celah: tabel **baru** otomatis bisa dibaca tanpa perlu `GRANT` manual lagi |

> **Menyesuaikan nama.** Ganti `armors_ro`, `nama_database_b`, dan `apps` dengan
> nilai milik lingkungan Anda. `app_name` pada Target PG B (`armors` di contoh)
> menentukan baris mana di `_sync.status` yang milik aplikasi tersebut.

### Menampilkan "data terakhir diperbarui pukul ..."

Aplikasi cukup melakukan satu `SELECT` ke schema `_sync` — tidak perlu menyentuh
tabel data sama sekali:

```sql
SELECT last_success_at, row_count
FROM _sync.status
WHERE target_kind = 'pg_b' AND app_name = 'armors';
```

Contoh keluaran:

| last_success_at | row_count |
| --- | --- |
| 2026-03-15 02:03:11.482+00 | 5 |

Tambahkan `dataset_name` ke daftar kolom bila aplikasi memakai lebih dari satu
dataset dari PostgreSQL B yang sama.

---

## Deploy

Set konfigurasi produksi di `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
```

| Variabel | Nilai | Alasan |
| --- | --- | --- |
| `APP_ENV` | `production` | — |
| `APP_DEBUG` | `false` | Pesan error tidak boleh membocorkan detail |
| `CACHE_STORE` | `redis` | **Wajib shared antar worker bila multi-host** |
| `SYNC_LOCK_STORE` | `redis` | Lock sinkronisasi harus terlihat oleh semua worker |

`SYNC_LOCK_STORE` menentukan cache store yang dipakai `Cache::lock()` untuk lock
per dataset. Kalau store-nya **tidak shared** (misalnya `array`, atau `file` pada
beberapa host terpisah), dua worker bisa menjalankan dataset yang sama bersamaan
dan lock tidak akan menahan siapa pun. Untuk instalasi multi-host, Redis wajib.

Jalankan migrasi dan cache:

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Bangun aset front-end:

```bash
npm ci && npm run build
```

Atur permission:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

Terakhir, pastikan `ods-worker` dan `ods-scheduler` berjalan (lihat
[Queue Worker & Scheduler](#queue-worker--scheduler)).

---

## Pengujian

```bash
php artisan test
```

Test memakai **PostgreSQL sungguhan**, bukan SQLite untuk sisi target. Ini
memang disengaja: engine bergantung pada fitur PostgreSQL yang tidak dimiliki
SQLite — *quoted identifier* huruf besar, schema terpisah, `ALTER TABLE ...
RENAME` yang bersifat transaksional, dan `ON CONFLICT`. Menguji dengan SQLite
akan menguji hal yang berbeda dari yang dijalankan di produksi.

Database yang dipakai (`phpunit.xml`):

| Peran | Database |
| --- | --- |
| Metadata | `ods_meta` |
| PG A | `ods_pg_a` |
| PG B (aplikasi `armors`) | `ods_pg_b_armors` |
| PG B kedua (aplikasi `reporting`) | `ods_pg_b_reporting` |

Buat lebih dulu:

```sql
CREATE DATABASE ods_meta;
CREATE DATABASE ods_pg_a;
CREATE DATABASE ods_pg_b_armors;
```

Variabel yang mengaturnya di `phpunit.xml`:

| Variabel | Nilai bawaan di `phpunit.xml` |
| --- | --- |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `5433` |
| `DB_DATABASE` | `ods_meta` |
| `TEST_PG_HOST` | `127.0.0.1` |
| `TEST_PG_PORT` | `5433` |
| `TEST_PG_USERNAME` / `TEST_PG_PASSWORD` | `postgres` / `postgres` |
| `TEST_PG_A_DATABASE` | `ods_pg_a` |
| `TEST_PG_B_DATABASE` | `ods_pg_b_armors` |
| `TEST_PG_B2_DATABASE` | `ods_pg_b_reporting` |
| `TEST_PG_RO_USER` / `TEST_PG_RO_PASSWORD` | `ods_ro` / `ods_ro_pass` |
| `SYNC_LOCK_STORE` | `array` |
| `QUEUE_CONNECTION` | `sync` |

> Database `ods_pg_b_reporting` juga perlu dibuat bila Anda menjalankan test yang
> menyalin ke dua PG B sekaligus.

Dua test suite tersedia: `Unit` (termasuk `SelectGuardTest`,
`OracleTypeMapperTest`, `CanonicalizerTest`) dan `Feature` (termasuk
`FullSyncTest`, `IncrementalSyncTest`, `DeletedRowsTest`, `ResilienceTest`).

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

---

## Checklist Serah Terima ke IT

> **Catatan.** Checklist ini memakai istilah panel UI sesuai rancangan aplikasi.
> Pada commit ini panel belum tersedia (`routes/web.php` baru memuat `/`), jadi
> setiap langkah yang menyebut tombol disertai perintah artisan yang setara.

- [ ] DBA membuat user Oracle `SELECT`-only dan menyerahkan kredensialnya ke IT. Perintah SQL-nya ada di bagian [Keamanan](#keamanan), butir 2. Tool tidak pernah membuat user di Oracle, jadi langkah ini wajib dikerjakan manual.
- [ ] Tim IT menyimpan salinan perintah SQL tersebut di dokumen serah terima internal, sehingga langkah ini bisa diverifikasi ulang.
- [ ] `oci8` terpasang di server; `php -m | grep oci8` menampilkan `oci8`.
- [ ] `.env` terisi: `APP_ENV=production`, `APP_DEBUG=false`, `SYNC_LOCK_STORE=redis`.
- [ ] `APP_KEY` produksi sudah dicatat di password manager — **jangan** menjalankan `key:generate` lagi.
- [ ] Connection dibuat dengan driver `oracle` dan kredensial DBA.
- [ ] Tombol **Tes Koneksi** ditekan dan hasilnya **OK**. Setara artisan: `php artisan oracle-sync:test-connection nama-koneksi`.
- [ ] Query `SELECT` ditulis di SQL Editor.
- [ ] Tombol **Preview** ditekan, lalu nama kolom, tipe Oracle, tipe PostgreSQL, dan setiap peringatan diperiksa. Batas 20 baris, tidak ada yang disimpan.
- [ ] Tombol **Simpan ke PG A** ditekan.
- [ ] Sinkronisasi pertama dijalankan.
- [ ] Verifikasi berhasil muncul di panel Riwayat, dan jumlah baris di sisi Oracle cocok.
- [ ] Nilai `_sync.status.last_success_at` disalin ke aplikasi agar bisa menampilkan "data terakhir diperbarui pukul ...".
- [ ] Target PG B untuk aplikasi dibuat, dengan `app_name` terisi.
- [ ] Tabel disalin dari A ke B, dan verifikasi berhasil muncul.
- [ ] User read-only untuk aplikasi dibuat, memakai perintah SQL di bagian [User Read-Only untuk PG B](#user-read-only-untuk-pg-b).
- [ ] `ods-worker` dan `ods-scheduler` aktif, atau cron `schedule:run` tiap menit aktif — **salah satu**, jangan keduanya.
- [ ] Tombol **Pause semua job** diuji lalu **Resume**. Setara artisan: `php artisan oracle-sync:pause --reason="tes"` lalu `php artisan oracle-sync:pause --resume`.
- [ ] Setelah satu jam dan setelah satu malam, jumlah baris di PG B tetap cocok dengan Oracle.

---

## Batasan yang Diketahui

Dinyatakan terbuka, bukan disembunyikan.

1. **Eksekusi driver `oracle` tidak bisa diuji otomatis di laptop developer.**
   Butuh ekstensi `oci8` dan instance Oracle sungguhan. Di automated test, jalur
   itu hanya diverifikasi lewat driver `mock` — yang memakai interface
   `SourceReader` identik, tetapi mesin SQL-nya berbeda. Konsekuensinya: test
   hijau **tidak** membuktikan query Anda valid di Oracle. Verifikasi terakhir
   tetap Preview di server oleh IT.

2. **Mock berjalan di atas SQLite, yang punya keterbatasan sendiri.** Hanya sekitar 15
   digit signifikan yang terjaga untuk angka, dan konstruksi Oracle yang tidak
   ada padanannya di SQLite (lihat tabel
   [Batasan mock](#batasan-mock--baca-ini-sebelum-menyimpulkan-apa-pun)) sama
   sekali tidak teruji. Mock memvalidasi *alur* tool, bukan *sintaks* Oracle.

3. **Mode incremental tidak mendeteksi baris yang dihapus.** Query incremental
   hanya mengambil baris yang berubah sejak sinkronisasi terakhir, lalu melakukan
   upsert. Baris yang **dihapus di Oracle** tidak akan pernah hilang dari
   PostgreSQL A. Karena itu mode incremental wajib disertai jadwal rekonsiliasi
   `full` berkala (`oracle-sync:reconcile`, default `0 2 * * *`). Peringatan ini
   juga sudah disiapkan di `Dataset::incrementalWarnings()` untuk ditampilkan
   setiap kali dataset incremental dibuka.

4. **Parsing SQL terbatas pada kasus umum.** Query yang tidak dikenali akan
   **ditolak dengan pesan jelas** — bukan dieksekusi lalu gagal diam-diam di
   tengah jalan, dan bukan diterjemahkan dengan tebakan. Driver `mock` juga
   menolak format `TO_CHAR` yang tidak dikenali, alih-alih menghasilkan data
   yang salah.

5. **Panel harus dijalankan lewat Vite saat pengembangan.** Halaman memakai Inertia
   + Vue 3. Di produksi, jalankan `npm run build` supaya aset ter-compile ke
   `public/build`. Tanpa itu, halaman akan kosong karena `@vite` tidak menemukan
   manifest. Alternatifnya: jalankan `npm run dev` bersamaan dengan
   `php artisan serve`.

6. **User `read-only` PostgreSQL mungkin tidak dapat dibuat otomatis.**
   `CREATE ROLE` butuh hak admin. Bila ditolak, sinkronisasi tetap berjalan dan
   hanya muncul peringatan; DBA harus menjalankan SQL secara manual.

7. **Endpoint refresh untuk aplikasi belum ada.** Nilainya sudah terdaftar di enum
   dan skema (`RunTriggerEnum::Api`, `runs.api_token_id`), tetapi belum ada route
   yang memanggilnya.

---

## Lisensi

Proyek ini memakai lisensi MIT, mengikuti kerangka Laravel yang menjadi
dasarnya.