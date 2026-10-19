# Audit Temuan — Oracle Data Sync

Status: **menunggu tackling** · Dibuat: 2026-10-16 · Baseline: `3889be1` (230 test hijau)

Dokumen ini berisi temuan audit yang **sudah diverifikasi**, bukan dugaan.
Setiap butir mencantumkan bukti, akar penyebab, dan usul perbaikan beserta
kriteria terima.

---

## Ringkasan

| Kode | Temuan | Severity | Status |
| --- | --- | --- | --- |
| B1 | `SYNC_LOCK_STORE` adalah konfigurasi mati | Tinggi | **Selesai** |
| B2 | `DatasetLock::run()` melapor salah saat cache mati | Tinggi | **Selesai** |
| B3 | Token plaintext tersimpan di tabel `sessions` | Tinggi | **Selesai** |
| B4 | `TrustProxies` tak dikonfigurasi, `allowed_ips` salah baca | Tinggi | **Selesai** |
| B5 | Login tanpa throttle | Sedang | **Selesai** |
| B6 | Tidak ada cara ganti password | Sedang | **Selesai** |
| D1 | `.env.example` masih default Laravel | Sedang | **Selesai** |
| D2 | Klaim README soal penyimpanan token kurang tepat | Rendah | **Selesai** |
| U1 | API tak bisa membedakan "sedang berjalan" | Rendah | **Selesai** |
| U2 | Dua lookup token per panggilan API | Rendah | **Selesai** |
| U3 | API balas 202 tanpa ada queue worker | Sedang | **Selesai** |
| U4 | Job gagal sebelum ada baris `runs` tak terlihat | Sedang | **Selesai** |
| U5 | Skrip dev tanpa guard `APP_ENV` | Sedang | **Selesai** |
| U6 | Rantai sync → copy dari pemicu API belum diuji | Rendah | **Selesai** |

**Semua butir pada audit ini sudah selesai.**

Urutan tackling yang disarankan: **B1 → B3 → B5 → B2 → B4**.
Semua butir sudah selesai (B1–B6, D1–D2, U1–U6).

> **B1 sudah tuntas.** Nilai `SYNC_LOCK_STORE` dibaca kode, dan `.env.example`
> sekarang memuatnya beserta penjelasan kapan Redis wajib — jadi instalasi baru
> tidak lagi diam-diam memakai lock yang tidak shared. `EnvExampleTest`
> menjaga berkas itu tidak kembali membusuk.

---

## BUG

### B1 — `SYNC_LOCK_STORE` adalah konfigurasi mati

**Severity: Tinggi** · **Status: SELESAI**

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

- [x] `SYNC_LOCK_STORE=redis` benar-benar membuat `Cache::store('redis')` yang dipakai
  untuk lock, terbukti lewat test.
- [x] `php artisan config:show oracle-sync` menampilkan nilai yang dipakai.
- [x] Ada test yang gagal kalau store yang dikonfigurasi tidak kompatibel dengan lock.

#### Yang dikerjakan

`DatasetLock` kini menyimpan nama store dari config dan memakainya secara
eksplisit:

```php
$resolved = Cache::store($this->store);

if (! $resolved->getStore() instanceof LockProvider) {
    throw new LockUnavailable('store "'.$store.'" tidak mendukung lock');
}

return $resolved->lock('sync:'.$key, $this->ttl());
```

`ttl_seconds` juga bisa dikonfigurasi lewat `SYNC_LOCK_TTL_SECONDS`, dan dibaca
per pemanggilan (bukan di constructor) supaya test bisa mengubahnya tanpa
membuat ulang objek.

Test: `test_lock_memakai_store_yang_dikonfigurasi` membuktikan lock benar-benar
jatuh ke store yang dikonfigurasi — ia mengambil lock itu lagi dari store
`array` dan berhasil, yang mustahil kalau lock sebenarnya ada di store
bawaan.

---

### B2 — `DatasetLock::run()` melapor salah saat cache mati

**Severity: Tinggi** · **Status: SELESAI**

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

- [x] Test dengan cache store yang sengaja dirusak menghasilkan `status = success`
  (bukan `skipped`), dan log memuat peringatan.
- [x] Test dengan lock yang benar-benar dipegang proses lain tetap menghasilkan
  `status = skipped`.

#### Yang dikerjakan

Dua kondisi dipisah dengan tipe berbeda. `acquire()` kini melempar
`App\Services\Guard\LockUnavailable` (file tersendiri, demi PSR-4), bukan
mengembalikan `null`:

```php
public function run(string $key, callable $callback): mixed
{
    try {
        $lock = $this->acquire($key);
    } catch (LockUnavailable $exception) {
        Log::warning('Lock sinkronisasi tidak bisa diambil; lanjut tanpa lock.', [
            'key' => $key,
            'store' => $this->store,
            'reason' => $exception->getMessage(),
        ]);

        return $callback();
    }

    if (! $lock->get()) {
        return null;   // hanya ini yang berarti "sedang berjalan"
    }

    try {
        return $callback();
    } finally {
        $this->release($lock);
    }
}
```

`null` sekarang secara eksklusif berarti "lock dipegang proses lain".
`SyncRunner` tidak perlu diubah karena maknanya sudah benar.

---

### B3 — Token plaintext tersimpan di tabel `sessions`

**Severity: Tinggi** · **Status: SELESAI**

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

- [x] Test end-to-end yang memaksa `session.driver=database` +
  `session.encrypt=false` membuktikan kondisi sebelum perbaikan, lalu
  membuktikannya hilang sesudah perbaikan.

#### Yang dikerjakan

**Satu: nilai token dienkripsi sebelum masuk session.** `Crypt::encryptString()`
dengan `APP_KEY`, jadi session yang bocor tidak cukup untuk mendapat token.

**Dua: flash-nya ditarik manual oleh `DriverController::index()`**, bukan
otomatis oleh Inertia, supaya dekripsi bisa terjadi di server sebelum plaintext
pergi ke browser. `Inertia::pullFlashed()` bersifat sekali pakai, jadi nilai
aslinya tetap hilang dari sisi server setelah render.

**Tiga: gagal tertutup di produksi.** `ApiTokenController` menolak menerbitkan
token kalau `app.env=production` dan `session.encrypt=false` — dicek **sebelum**
token dibuat, supaya penolakan tidak menyisakan token tersimpan yang tidak bisa
ditampilkan. Di luar produksi tetap dibolehkan supaya development tidak
tersendat.

Test `test_token_tidak_ada_plaintext_di_tabel_sessions` memaksa session ke driver
`database` dengan enkripsi mati, lalu membaca isi tabel `sessions` secara
mentah dan memastikan nilai asli tidak ada di sana.

---

### B4 — `TrustProxies` tak dikonfigurasi, `allowed_ips` salah baca

**Severity: Tinggi** · **Status: SELESAI**

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

- [x] Test mensimulasikan header proxy dan memastikan IP yang dipakai adalah IP klien.
- [x] Perilaku "proxy tidak dikonfigurasi" diuji secara eksplisit.

#### Yang dikerjakan

**`TRUSTED_PROXIES` jadi konfigurasi nyata.** Dibaca lewat
`App\Support\TrustedProxy`, dipasang di `bootstrap/app.php`:

```php
$middleware->trustProxies(
    at: TrustedProxy::list(),
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB,
);
```

Kosong secara default, dan itu disengaja. **`'*'` tidak pernah dipakai** — begitu
wildcard aktif, siapa pun bisa memalsukan `X-Forwarded-For` dan pembatasan IP
kehilangan seluruh artinya.

**Gagal tertutup kalau IP klien tidak bisa dipastikan.** `AuthenticateApiToken`
menolak request bertoken yang punya daftar IP, bila header proxy terlihat tapi
tidak ada proxy tepercaya:

```json
{
  "message": "IP klien tidak bisa dipastikan, jadi pembatasan IP pada token tidak dapat dievaluasi. Set TRUSTED_PROXIES untuk aplikasi ini.",
  "reason": "proxy_header_present_but_untrusted:X-Forwarded-For"
}
```

Diperiksa **sebelum** `allowsIp()`. Kalau urutannya dibalik, request sudah
ditolak 403 dengan alasan yang menyesatkan sebelum pesan yang benar ini sempat
muncul.

Token tanpa daftar IP tidak terganggu — header proxy yang tidak dipercaya tidak
mengganggunya, karena bagaimanapun IP-nya tidak relevan.

---

### B5 — Login tanpa throttle

**Severity: Sedang** · **Status: SELESAI**

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

- [x] Test: percobaan ke-6 dalam semenit ditolak dengan 429.
- [x] Pesan error tidak membocorkan apakah email terdaftar.

#### Yang dikerjakan

```php
Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:login'])
    ->name('login.store');
```

Limiter `login` didaftarkan di `SyncServiceProvider` dengan kunci per
**kombinasi email + IP**, bukan per IP saja:

```php
RateLimiter::for('login', function (Request $request) {
    $key = 'login:'.Str::transliterate(
        Str::lower((string) $request->input('email', '')).'|'.$request->ip()
    );

    return Limit::perMinute(5)->by($key);
});
```

Per kombinasi, bukan per IP, supaya satu jaringan korporat tidak saling
mengunci padahal orangnya berbeda.

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

**Status: SELESAI**

`.env.example` ditulis ulang: seluruh variabel yang dibutuhkan tool ini, dengan
nilai yang disamakan dengan `phpunit.xml` supaya test langsung jalan, plus
penjelasan singkat untuk yang rawan salah paham (`SYNC_LOCK_STORE`,
`SESSION_ENCRYPT`, `TRUSTED_PROXIES`).

Yang paling penting: **`EnvExampleTest` (6 test)** sekarang menjaga berkas itu
tidak kembali membusuk. Begitu ada variabel baru yang dibutuhkan, test gagal
sampai `.env.example` ikut diperbarui. Salah satu testnya juga memastikan
`DB_PORT` di `.env.example` sama dengan `phpunit.xml` — persis jebakan yang
tidak terduga saat pindah mesin.

---

## Yang dulu belum dikerjakan, sekarang selesai

Tidak ada butir yang tertinggal. Yang berikut adalah ringkasan cara setiap
butir ditutup beserta test yang menjaganya.

### U1 — API tak bisa membedakan "sedang berjalan" · **SELESAI**

Sumbernya tabel `runs`, bukan cache lock — cache lock tidak bisa diperiksa
tanpa mengambilnya, dan mengambilnya justru akan mengunci dataset yang sedang
bebas.

```php
public static function isDatasetRunning(Dataset $dataset): bool
{
    $window = max(60, (int) $dataset->timeout_seconds) + 300;

    return $dataset->runs()
        ->where('status', RunStatusEnum::Running)
        ->where('started_at', '>=', now()->subSeconds($window))
        ->exists();
}
```

Batas waktunya penting: tanpa itu, run yang yatim — worker mati mendadak
sehingga `finished_at` tak pernah terisi — akan membuat dataset terlihat
"sedang berjalan" selamanya.

Endpoint kini membalas `200` dengan `status: skipped` kalau sedang berjalan.
`200`, bukan `409`, karena permintaannya sebenarnya sudah terpenuhi oleh proses
yang sedang jalan.

Test: `test_dataset_terdeteksi_sedang_berjalan`, `test_run_yatim_tidak_menahan_dataset_selamanya`,
`test_run_yang_sudah_selesai_tidak_terhitung_berjalan`,
`test_menyatakan_sedang_berjalan_dari_tabel_runs`.

### U2 — Dua lookup token per panggilan API · **SELESAI**

`App\Services\Api\ApiTokenLookup` meng-cache hasil pencarian di atribut request,
sehingga rate limiter dan middleware memakai satu query yang sama.

`markUsed()` juga tidak lagi menulis setiap panggilan — paling sering sekali
per menit, karena presisi detik tidak relevan untuk kolom "terakhir dipakai".

### U3 — API balas 202 tanpa ada queue worker · **SELESAI**

Ditambah tanda denyut: `php artisan ods:queue-heartbeat`, dijadwalkan setiap
menit. Disimpan di tabel `settings` — bukan cache — supaya konsisten antar
worker dan bertahan setelah restart.

Endpoint sekarang membalas `503` dengan `status: worker_unavailable` kalau
heartbeat-nya basi, beserta perintah yang harus dijalankan. Job tidak lagi
menggantung diam-diam.

Kalau heartbeat **belum pernah ada** (`known: false`), statusnya dianggap
"netral", bukan "mati" — supaya instalasi yang tidak mengaktifkan scheduler
tidak ikut ditolak.

Test: `test_heartbeat_segera_dinyatakan_hidup`, `test_heartbeat_basi_dinyatakan_mati`,
`test_heartbeat_rusak_tidak_membikin_panik`, `test_menolak_dengan_503_saat_worker_mati`,
`test_heartbeat_segar_mengizinkan_penjadwalan`.

### U4 — Job gagal sebelum ada baris `runs` tak terlihat · **SELESAI**

`SyncDatasetJob::handle()` dibungkus try/catch. Kegagalan di luar
`SyncRunner` sekarang selalu meninggalkan baris `runs` berstatus `failed`,
supaya terlihat di halaman Riwayat. Ditambah `failed()` untuk mencatat ke log
saat job mati permanen.

> Bug yang ketemu saat mengerjakan ini: `recordUncaughtFailure()` sempat
> memakai variabel `$runner` yang tidak ada di parameternya. Error-nya
> tertangkap `catch` di dalamnya sendiri, jadi pemanggilnya mengira pencatatan
> berhasil padahal tidak ada yang tercatat. Persis kelas bug yang item ini
> tuju: gagal tanpa error yang terlihat. Itu sebabnya testnya harus
> behavioral, bukan sekadar mencocokkan string.

### U5 — Skrip dev tanpa guard `APP_ENV` · **SELESAI**

`buat-akun.php` dan `isi-data-contoh.php` menolak jalan saat
`APP_ENV=production`, dicek **sebelum** ada yang ditulis ke database. Pesan
penolakan ditulis ke STDERR supaya terlihat di terminal.

Testnya behavioral, bukan cuma mencocokkan string: skrip benar-benar
dijalankan dalam environment produksi lalu diperiksa tidak ada akun, koneksi,
target, dataset, atau token yang tercipta.

### U6 — Rantai sync → copy dari pemicu API belum diuji · **SELESAI**

Ditambah test end-to-end: job yang dipicu dengan `auto_copy_to` benar-benar
menjalankan sync lalu menyalin ke PG B, dan isi PG B dibandingkan isi PG A
baris per baris.

---
## Catatan umum

- Jumlah test saat audit: **230 lulus / 591 assertion**. Tidak ada regresi.
- Semua temuan di atas diverifikasi terhadap baseline `3889be1` plus koreksi
  dokumentasi `e284619`.
- Seluruh butir di dokumen ini sudah dikerjakan, masing-masing dengan test
  yang menjaganya.
