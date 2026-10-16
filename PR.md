# Audit Temuan — Oracle Data Sync

Status: **menunggu tackling** · Dibuat: 2026-10-16 · Baseline: `3889be1` (230 test hijau)

Dokumen ini berisi temuan audit yang **sudah diverifikasi**, bukan dugaan.
Setiap butir mencantumkan bukti, akar penyebab, dan usul perbaikan beserta
kriteria terima.

---

## Ringkasan

| Kode | Temuan | Severity | Status |
| --- | --- | --- | --- |
| B1 | `SYNC_LOCK_STORE` adalah konfigurasi mati | Tinggi | Belum |
| B2 | `DatasetLock::run()` melapor salah saat cache mati | Tinggi | Belum |
| B3 | Token plaintext tersimpan di tabel `sessions` | Tinggi | Belum |
| B4 | `TrustProxies` tak dikonfigurasi, `allowed_ips` salah baca | Tinggi | Belum |
| B5 | Login tanpa throttle | Sedang | Belum |
| B6 | Tidak ada cara ganti password | Sedang | Belum |
| D1 | `.env.example` masih default Laravel | Sedang | Belum |
| D2 | Klaim README soal penyimpanan token kurang tepat | Rendah | Sudah dikoreksi (teks) |
| U1 | API tak bisa membedakan "sedang berjalan" | Rendah | Belum |
| U2 | Dua lookup token per panggilan API | Rendah | Belum |
| U3 | API balas 202 tanpa ada queue worker | Sedang | Belum |
| U4 | Job gagal sebelum ada baris `runs` tak terlihat | Sedang | Belum |
| U5 | Skrip dev tanpa guard `APP_ENV` | Sedang | Belum |
| U6 | Rantai sync → copy dari pemicu API belum diuji | Rendah | Belum |

Urutan tackling yang disarankan: **B1 → B3 → B5 → B2 → B4**.

---

## BUG

### B1 — `SYNC_LOCK_STORE` adalah konfigurasi mati

**Severity: Tinggi**

#### Gejala

`config/oracle-sync.php:106` mendefinisikan:

```php
'lock' => [
    'ttl_seconds' => 900,
    'store' => env('SYNC_LOCK_STORE', 'database'),
],
```

Tapi **tidak ada kode yang membaca `oracle-sync.lock.store`**. Pencarian di
seluruh `app/` hanya menemukan pembacaan `ttl_seconds`
(`app/Providers/SyncServiceProvider.php:18`) dan penyebutan nama variabel di
sebuah komentar (`app/Services/Guard/DatasetLock.php:13`).

`DatasetLock::acquire()` memakai `Cache::lock()`:

```php
return Cache::lock('sync:'.$key, $this->ttlSeconds);
```

`Cache::lock()` memakai store **bawaan** (`CACHE_STORE`), bukan
`SYNC_LOCK_STORE`.

#### Dampak

README memerintahkan: *"Untuk instalasi multi-host, Redis wajib"* dan menyodorkan
`SYNC_LOCK_STORE=redis` sebagai cara mengaturnya. Mengikuti instruksi itu **tidak
mengubah perilaku apa pun**.

Pada instalasi multi-host, dua worker di host berbeda bisa menjalankan dataset
yang sama bersamaan karena lock mereka tidak sama-sama terlihat. Ini persis
masalah yang diklaim dipecahkan oleh instruksi tersebut.

Catatan tambahan: `ttl_seconds` juga **tidak** dibaca dari environment — nilainya
hardcoded `900`.

#### Akar penyebab

Konfigurasi ditulis lalu didokumentasikan, tapi tidak pernah disambungkan ke
consumer-nya.

#### Usul perbaikan

Bacanya di `DatasetLock::acquire()`:

```php
$store = config('oracle-sync.lock.store');

return Cache::store($store)->lock('sync:'.$key, $this->ttlSeconds);
```

Sekalian jadikan `ttl_seconds` bisa dikonfigurasi lewat environment.

#### Kriteria terima

- `SYNC_LOCK_STORE=redis` benar-benar membuat `Cache::store('redis')` yang dipakai
  untuk lock, terbukti lewat test.
- `php artisan config:show oracle-sync` menampilkan nilai yang dipakai.
- Ada test yang gagal kalau store yang dikonfigurasi tidak kompatibel dengan lock.

---

### B2 — `DatasetLock::run()` melapor salah saat cache mati

**Severity: Tinggi**

#### Gejala

`app/Services/Guard/DatasetLock.php` memuat komentar yang bertentangan dengan
implementasinya:

```php
public function acquire(string $key): ?Lock
{
    try {
        return Cache::lock('sync:'.$key, $this->ttlSeconds);
    } catch (Throwable) {
        // Tanpa cache yang bisa dipakai, lebih aman proceed tanpa lock
        // daripada memblokir seluruh sinkronisasi.
        return null;
    }
}
```

Komentar itu menjanjikan *proceed tanpa lock*. Yang sebenarnya terjadi di
`run()`:

```php
if ($lock === null || ! $lock->get()) {
    return null;
}
```

`null` diteruskan ke `SyncRunner::run()`, yang memakainya sebagai:

```php
if ($result === null) {
    $this->finishSkipped($run, 'Dataset ini sedang berjalan di proses lain.');
    return $run;
}
```

#### Dampak

Kalau Redis / cache store tumbang, **seluruh sinkronisasi berhenti**, tanpa
error, tanpa log, dengan pesan *"Dataset ini sedang berjalan di proses lain."*

Operator akan menghabiskan waktu berjam-jam mencari dataset yang sedang terkunci
padahal tidak ada yang jalan. Fail-safe yang dimaksud (proceed tanpa lock)
justru tidak pernah terjadi.

#### Akar penyebab

Dua kondisi berbeda — "sudah terkunci" dan "tidak bisa mengunci" — direpresentasikan
oleh nilai kembalian yang sama, `null`.

#### Usul perbaikan

Bedakan keduanya secara eksplisit, misalnya lewat nilai kembalian khusus atau
exception, lalu beri pesan yang jujur di `SyncRunner`:

- `already_running` → status `skipped`, pesan "sedang berjalan di proses lain"
- `lock_unavailable` → **proceed** seperti niatnya awal, dan catat peringatan ke
  log

#### Kriteria terima

- Test dengan cache store yang sengaja dirusak menghasilkan `status = success`
  (bukan `skipped`), dan log memuat peringatan.
- Test dengan lock yang benar-benar dipegang proses lain tetap menghasilkan
  `status = skipped`.

---

### B3 — Token plaintext tersimpan di tabel `sessions`

**Severity: Tinggi**

#### Gejala

Nilai asli token dikirim ke browser lewat flash Inertia. Flash berarti lewat
session, dan session memakai driver `database` dengan enkripsi nonaktif:

```
SESSION_DRIVER=database
SESSION_ENCRYPT=false
```

Bukti — isi tabel `sessions` tepat setelah token diterbitkan, masih di dalam
session, sudah di-decode dari base64:

```
s:5:"plain";s:64:"sIVtOSOmiEglc1ziVSEhbTZzsR2cJQWZxbAWVoPyP3khxN2BB7rTgrpkiiBuu0N1";
```

Nilai aslinya ada di sana, plaintext, hanya dibungkus base64.

#### Dampak

Menyimpan token sebagai SHA-256 **tidak cukup** selama session tidak terenkripsi:
ada salinan plaintext yang menetap di database metadata sampai sesi kadaluarsa
(default `SESSION_LIFETIME=120` menit).

Siapa pun yang bisa membaca tabel `sessions` — termasuk dump database, backup,
replika read-only, atau log — mendapat token yang seharusnya hanya ada di
browser admin.

#### Akar penyebab

Flash Inertia otomatis memakai session sebagai alat pembawa. Tidak ada
pertimbangan bahwa payload-nya adalah rahasia yang einmal pakai.

#### Usul perbaikan

Dua lapis, keduanya sebaiknya dikerjakan:

1. **`SESSION_ENCRYPT=true`** di produksi. Sudah didokumentasikan di README pada
   baseline `e284619`, tapi belum di-enforce di kode.
2. **Kurangi waktu paparan.** Alternatif yang lebih bersih: jangan lewat session
   sama sekali. Balas `POST /drivers/tokens` dengan JSON berisi token, lalu
   admin baru melakukan GET ke URL sekali-pakai yang memverifikasi hash-nya.
   Dengan begitu plaintext tidak pernah masuk state server.

#### Kriteria terima

- Test end-to-end yang memaksa `session.driver=database` +
  `session.encrypt=false` membuktikan kondisi sebelum perbaikan, lalu
  membuktikannya hilang sesudah perbaikan.

---

### B4 — `TrustProxies` tak dikonfigurasi, `allowed_ips` salah baca

**Severity: Tinggi**

#### Gejala

`app/Http/Middleware/AuthenticateApiToken.php` memvalidasi IP pemanggil:

```php
if (! $token->allowsIp($request->ip())) {
    return response()->json(['message' => 'Alamat IP tidak diizinkan untuk token ini.'], 403);
}
```

`bootstrap/app.php` tidak pernah memanggil `trustProxies(...)`, dan
`.env.example` tidak punya `TRUSTED_PROXIES`.

#### Dampak

Di belakang reverse proxy — yang nyaris pasti ada di server internal — `$request->ip()`
mengembalikan **IP proxy**, bukan IP klien. Dua kemungkinan, sama-sama buruk:

- IP proxy tidak terdaftar ⇒ setiap token bertapis IP **ditolak**, fitur ini
  tidak bisa dipakai sama sekali di produksi.
- IP proxy kebetulan masuk daftar → **semua klien lolos**, dan pembatasan
  tersebut sepenuhnya tidak berlaku.

Fitur keamanan yang terlihat berguna tapi efektifnya nol, atau lebih buruk
lagi, sama sekali tidak berlaku.

#### Akar penyebab

`allowed_ips` dirancang seolah-olah request datang langsung ke aplikasi, tanpa
mempertimbangkan deployment di belakang proxy.

#### Usul perbaikan

- Konfigurasi `trustProxies` dan `TRUSTED_PROXIES` di README + `.env.example`.
- Test dengan `X-Forwarded-For` yang mensimulasikan proxy, memastikan IP klien
  yang dibaca, bukan IP proxy.
- Gagal **tertutup** kalau `allowed_ips` disetel tapi `TRUSTED_PROXIES` kosong di
  `production` — lebih baik request ditolak daripada lolos diam-diam.

#### Kriteria terima

- Test mensimulasikan header proxy dan memastikan IP yang dipakai adalah IP klien.
- Perilaku "proxy tidak dikonfigurasi" diuji secara eksplisit.

---

### B5 — Login tanpa throttle

**Severity: Sedang**

#### Gejala

Tidak ada `throttle` sama sekali di `routes/web.php`. Rute login:

```php
Route::post('/login', [LoginController::class, 'store'])->middleware('guest')->name('login.store');
```

`'guest'` hanya mencegah login ulang saat sudah masuk — bukan membatasi percobaan.

#### Dampak

Panel ini adalah satu-satunya jalan masuk ke kredensial koneksi dan token API.
Tanpa throttle, brute force password berjalan tanpa hambatan. Password admin
bawaan pun lemah (`ods12345`).

#### Usul perbaikan

```php
Route::post('/login', ...)
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login.store');
```

Tambahkan juga pembatas berbasis IP pada API (sebagian sudah ada, tapi hanya
setelah lookup token).

#### Kriteria terima

- Test: percobaan ke-11 dalam semenit ditolak dengan 429.
- Pesan error tidak membocorkan apakah email terdaftar.

---

### B6 — Tidak ada cara ganti password

**Severity: Sedang**

#### Gejala

Nol route untuk password, user, atau profil di `routes/web.php`. Satu-satunya
cara membuat akun adalah skrip `buat-akun.php` di shell.

#### Dampak

Password admin praktis permanen selama tool ini hidup. Begitu `ods12345` bocor —
misal lewat dump `sessions` (lihat B3) — tidak ada jalur pemulihan yang wajar.

#### Usul perbaikan

Halaman profil sederhana: ganti password (wajib isi password lama), dan daftar
sesi aktif dengan tombol cabut. Scope-nya kecil, tapi menutup risiko B3 dan B5.

#### Kriteria terima

- Admin bisa ganti password lewat panel.
- Password lama salah ⇒ ditolak.
- Setelah ganti, password lama tidak bisa dipakai login.

---

## Dokumentasi yang tidak sesuai kenyataan

| Klaim | Kenyataan | Status |
| --- | --- | --- |
| "`SYNC_LOCK_STORE` dipakai untuk lock, Redis wajib multi-host" | Setting-nya tidak dibaca kode mana pun (lihat B1) | Belum |
| "Token tidak pernah disimpan" | Tersimpan plaintext di tabel `sessions` (lihat B3) | Teks sudah dikoreksi di `e284619`; kode belum |
| `.env.example` sebagai acuan setup | Masih default Laravel: `APP_NAME=Laravel`, `DB_CONNECTION=sqlite`, nol konfigurasi Oracle/sync/API | Belum |

### D1 — `.env.example` masih default Laravel

IT yang ikut berkas ini akan salah setup total: namespace database yang keliru,
driver `sqlite`, dan tidak ada satu pun variabel yang dibutuhkan tool ini
(`SYNC_LOCK_STORE`, driver OCI8, batas laju API, dan seterusnya).

Perlu diisi ulang dari `.env` yang benar-benar dipakai, dengan nilai yang
terverifikasi bekerja — bukan apa adanya.

---

## Belum dikerjakan

### U1 — API tak bisa membedakan "sedang berjalan"

`POST /refresh` selalu membalas `202` selama dataset aktif dan kill switch
mati, tanpa memberi tahu apakah sinkronisasi sedang berjalan. Aplikasi tidak
bisa membedakan "baru dijalankan" dari "sudah ada yang jalan".

Batasnya disengaja dan sudah dikomentari di controller: satu-satunya cara
memeriksa cache lock tanpa mengambilnya adalah dengan mengambilnya. Kalau
dibutuhkan, jalurnya baca kolom `runs` (`status = running`) dengan batas waktu
supaya run yang yatim tidak bikin status nyangkut.

### U2 — Dua lookup token per panggilan API

`throttle:api.token` dan `api.token` sama-sama memanggil
`ApiToken::findByPlainToken()`, ditambah `markUsed()` menulis di setiap
panggilan. Ketiganya kena `CACHE_STORE=database`.

Dengan batas bawaan 10/menit ini masih wajar, tapi borosannya mudah dihilangkan:
cari token sekali per request lalu simpan di atribut request.

### U3 — API balas 202 walau tidak ada queue worker

Job masuk antrean `jobs` dan menggantung selamanya, sementara aplikasi sudah
diberi tahu "Sinkronisasi dijadwalkan". Tidak ada pemeriksaan apakah worker hidup.

Ide perbaikan: heartbeat worker di tabel `settings`, atau beri batas waktu tunggu
singkat sebelum membalas 202.

### U4 — Job gagal sebelum ada baris `runs` tak terlihat

`SyncDatasetJob` memakai `public int $tries = 1`. Kalau job mati sebelum
`SyncRunner::startRun()` sempat membuat baris `runs` (OOM, timeout, kill), tidak
ada jejak di halaman Riwayat — hanya di `queue:failed`, dan tidak ada monitoring
yang menyuruh orang melihat ke sana.

### U5 — Skrip dev tanpa guard `APP_ENV`

`buat-akun.php` dan `isi-data-contoh.php` ada di root repo tanpa pemeriksaan
environment. Siapa pun dengan shell di server produksi bisa menjalankan
`buat-akun.php` dan membuat akun admin dengan password yang diketahui.

Keduanya perlu menolak jalan kalau `APP_ENV=production`.

### U6 — Rantai sync → copy dari pemicu API belum diuji end-to-end

`SyncDatasetJob` meneruskan ke `CopyTableJob` untuk setiap target di
`auto_copy_to`. `ApiRefreshTest` hanya memastikan job-nya terkirim, belum
memastikan rantai copy-nya benar-benar jalan dari pemicu API.

---

## Catatan umum

- Jumlah test saat audit: **230 lulus / 591 assertion**. Tidak ada regresi.
- Semua temuan di atas diverifikasi terhadap baseline `3889be1` plus koreksi
  dokumentasi `e284619`.
- Tidak satu pun butir di dokumen ini sudah dikerjakan, kecuali koreksi teks
  pada D2. Kriteria terima di setiap bagian dimaksudkan supaya bisa langsung
  dijadikan checklist saat tackling.
