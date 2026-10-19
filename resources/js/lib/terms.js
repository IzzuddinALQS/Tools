/*
 * Kamus istilah: satu tempat untuk semua kata yang dilihat pengguna.
 *
 * Tool ini dipakai orang yang belum tentu paham PostgreSQL, cron, atau
 * watermark. Istilah teknis tetap disebut (dalam kurung / tooltip) supaya
 * pengguna bisa mencocokkannya dengan dokumentasi, tapi label utamanya
 * memakai bahasa sehari-hari.
 */

export const TERMS = {
  source: {
    label: 'Sumber Oracle',
    help: 'Database Oracle tempat data asli berada. Tool ini hanya MEMBACA dari sini dengan akun yang cuma punya hak SELECT — tidak pernah menulis.',
  },
  pgA: {
    label: 'Database Penampung',
    short: 'Penampung',
    tech: 'PostgreSQL A',
    help: 'PostgreSQL A: tempat hasil query Oracle disimpan pertama kali. Hanya ada satu. Menjadi "salinan induk" yang selalu bisa dipakai untuk memulihkan database aplikasi.',
  },
  pgB: {
    label: 'Database Aplikasi',
    short: 'Aplikasi',
    tech: 'PostgreSQL B',
    help: 'PostgreSQL B: salinan dari Database Penampung yang dibaca oleh aplikasi Anda. Boleh lebih dari satu, satu per aplikasi.',
  },
  dataset: {
    label: 'Dataset',
    help: 'Satu query SELECT yang hasilnya disimpan sebagai satu tabel. Dataset bisa dijalankan manual atau terjadwal.',
  },
  sync: {
    label: 'Ambil data',
    help: 'Menjalankan query ke Oracle, lalu menyimpan hasilnya ke Database Penampung.',
  },
  copy: {
    label: 'Kirim ke aplikasi',
    help: 'Menyalin satu tabel dari Database Penampung ke Database Aplikasi. Isi tabel di Database Aplikasi akan diganti persis sama.',
  },
  reconcile: {
    label: 'Penyelarasan penuh',
    help: 'Mengambil ulang SEMUA data agar baris yang dihapus di Oracle ikut terhapus. Wajib dijadwalkan untuk mode "Hanya data berubah".',
  },
  pause: {
    label: 'Hentikan semua',
    help: 'Tombol darurat. Selama aktif, tidak ada data yang ditulis ke database mana pun. Proses yang sedang berjalan berhenti dengan aman.',
  },
  verify: {
    label: 'Pemeriksaan data',
    help: 'Setelah data dipindahkan, isinya dibandingkan baris per baris memakai sidik jari (hash). Jika cocok, data di tujuan dijamin identik dengan sumber.',
  },
  fullMode: {
    label: 'Ambil semua data',
    help: 'Setiap kali dijalankan, seluruh hasil query diambil ulang. Paling aman dan disarankan.',
  },
  incrementalMode: {
    label: 'Hanya data yang berubah',
    help: 'Hanya mengambil baris yang baru/berubah sejak terakhir kali. Lebih cepat untuk tabel besar, tapi baris yang DIHAPUS di Oracle tidak ikut terhapus sampai penyelarasan penuh berjalan.',
  },
  watermark: {
    label: 'Kolom waktu perubahan',
    help: 'Kolom yang selalu diperbarui saat baris berubah, misalnya LAST_UPDATE_DATE. Dipakai untuk tahu baris mana yang baru.',
  },
  keyColumns: {
    label: 'Kolom ID unik',
    help: 'Kolom yang nilainya unik per baris (seperti primary key). Dipakai untuk mengganti baris lama dengan versi terbarunya.',
  },
  readonly: {
    label: 'Akun baca-saja',
    help: 'Akun database yang hanya bisa membaca. Aplikasi sebaiknya memakai akun ini, bukan akun pemilik, supaya tidak bisa mengubah data secara tidak sengaja.',
  },
  apiToken: {
    label: 'Akses API',
    help: 'Kunci rahasia agar aplikasi lain bisa meminta "ambil data sekarang" untuk satu dataset tertentu. Kunci tidak bisa menulis SQL atau membaca Oracle.',
  },
  mock: {
    label: 'Data contoh',
    help: 'Oracle tiruan berisi data contoh. Cocok untuk mencoba tool ini tanpa akses Oracle sungguhan.',
  },
}

export const RUN_STATUS = {
  success: { label: 'Berhasil', cls: 'badge-success', dot: 'bg-emerald-500' },
  failed: { label: 'Gagal', cls: 'badge-danger', dot: 'bg-red-500' },
  running: { label: 'Sedang berjalan', cls: 'badge-info', dot: 'bg-brand-500' },
  queued: { label: 'Menunggu giliran', cls: 'badge-neutral', dot: 'bg-ink-400' },
  skipped: { label: 'Dilewati', cls: 'badge-warning', dot: 'bg-amber-500' },
  paused: { label: 'Dihentikan sementara', cls: 'badge-purple', dot: 'bg-purple-500' },
  active: { label: 'Aktif', cls: 'badge-success', dot: 'bg-emerald-500' },
}

export const RUN_KIND = {
  sync: 'Ambil data',
  copy: 'Kirim ke aplikasi',
  reconcile: 'Penyelarasan penuh',
  test: 'Tes koneksi',
}

export const RUN_TRIGGER = {
  manual: 'manual',
  schedule: 'terjadwal',
  api: 'lewat API',
  reconcile: 'penyelarasan',
}

export const SYNC_MODE = {
  full: TERMS.fullMode.label,
  incremental: TERMS.incrementalMode.label,
}

export const TARGET_KIND = {
  pg_a: TERMS.pgA.label,
  pg_b: TERMS.pgB.label,
}

/* ---------------------------------------------------------------
   Format angka & waktu
--------------------------------------------------------------- */

const numberFormat = new Intl.NumberFormat('id-ID')

export function formatNumber(value) {
  if (value === null || value === undefined || value === '') return '—'
  return numberFormat.format(Number(value))
}

export function formatDuration(ms) {
  if (ms === null || ms === undefined) return '—'
  const total = Number(ms)
  if (total < 1000) return `${total} md`

  const seconds = Math.round(total / 1000)
  if (seconds < 60) return `${seconds} dtk`

  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  if (minutes < 60) return rest ? `${minutes} mnt ${rest} dtk` : `${minutes} mnt`

  const hours = Math.floor(minutes / 60)
  const restMin = minutes % 60
  return restMin ? `${hours} jam ${restMin} mnt` : `${hours} jam`
}

/** Server mengirim "YYYY-MM-DD HH:MM:SS" (waktu lokal server). */
function parseDate(value) {
  if (!value) return null
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? null : date
}

const dateFormat = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
})

export function formatDate(value) {
  const date = parseDate(value)
  return date ? dateFormat.format(date) : '—'
}

export function timeAgo(value) {
  const date = parseDate(value)
  if (!date) return '—'

  const diff = Math.round((Date.now() - date.getTime()) / 1000)
  if (diff < 0) return formatDate(value)
  if (diff < 45) return 'baru saja'
  if (diff < 3600) return `${Math.max(1, Math.round(diff / 60))} menit lalu`
  if (diff < 86400) return `${Math.round(diff / 3600)} jam lalu`
  if (diff < 86400 * 7) return `${Math.round(diff / 86400)} hari lalu`
  return formatDate(value)
}

/* ---------------------------------------------------------------
   Jadwal (cron) ⇄ bahasa manusia
--------------------------------------------------------------- */

export const WEEKDAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

const pad = (n) => String(n).padStart(2, '0')
const isInt = (v) => /^\d+$/.test(v)

/**
 * Ubah ekspresi cron menjadi objek jadwal yang dipahami SchedulePicker.
 * Pola yang tidak dikenali dikembalikan sebagai { type: 'custom' }.
 */
export function parseCron(expr) {
  const value = (expr ?? '').trim()
  if (value === '') return { type: 'manual' }

  const parts = value.split(/\s+/)
  if (parts.length !== 5) return { type: 'custom', cron: value }

  const [min, hour, dom, mon, dow] = parts
  const everyDay = dom === '*' && mon === '*'

  let m
  if ((m = min.match(/^\*\/(\d+)$/)) && hour === '*' && everyDay && dow === '*') {
    return { type: 'minutes', every: Number(m[1]) }
  }
  if (min === '*' && hour === '*' && everyDay && dow === '*') {
    return { type: 'minutes', every: 1 }
  }
  if (isInt(min) && hour === '*' && everyDay && dow === '*') {
    return { type: 'hourly', every: 1, minute: Number(min) }
  }
  if (isInt(min) && (m = hour.match(/^\*\/(\d+)$/)) && everyDay && dow === '*') {
    return { type: 'hourly', every: Number(m[1]), minute: Number(min) }
  }
  if (isInt(min) && isInt(hour) && everyDay && dow === '*') {
    return { type: 'daily', time: `${pad(hour)}:${pad(min)}` }
  }
  if (isInt(min) && isInt(hour) && everyDay && isInt(dow)) {
    return { type: 'weekly', day: Number(dow) % 7, time: `${pad(hour)}:${pad(min)}` }
  }

  return { type: 'custom', cron: value }
}

export function buildCron(schedule) {
  const [h, m] = (schedule.time ?? '00:00').split(':').map(Number)

  switch (schedule.type) {
    case 'manual': return ''
    case 'minutes': return schedule.every === 1 ? '* * * * *' : `*/${schedule.every} * * * *`
    case 'hourly': return schedule.every === 1
      ? `${schedule.minute ?? 0} * * * *`
      : `${schedule.minute ?? 0} */${schedule.every} * * *`
    case 'daily': return `${m || 0} ${h || 0} * * *`
    case 'weekly': return `${m || 0} ${h || 0} * * ${schedule.day ?? 1}`
    default: return schedule.cron ?? ''
  }
}

export function describeCron(expr) {
  const s = parseCron(expr)

  switch (s.type) {
    case 'manual': return 'Hanya manual'
    case 'minutes': return s.every === 1 ? 'Setiap menit' : `Setiap ${s.every} menit`
    case 'hourly': return s.every === 1
      ? (s.minute ? `Setiap jam, menit ke-${s.minute}` : 'Setiap jam')
      : `Setiap ${s.every} jam`
    case 'daily': return `Setiap hari pukul ${s.time}`
    case 'weekly': return `Setiap ${WEEKDAYS[s.day]} pukul ${s.time}`
    default: return `Jadwal khusus (${s.cron})`
  }
}

/* ---------------------------------------------------------------
   Lain-lain
--------------------------------------------------------------- */

export function verificationState(value) {
  if (value === 'ok' || value === true) return true
  if (value === 'failed' || value === false) return false
  return null
}

/*
 * Terjemahan pesan error teknis (dari Oracle/PostgreSQL/PHP) ke bahasa
 * sehari-hari. Pesan aslinya tetap ditampilkan sebagai detail teknis.
 */
const ERROR_PATTERNS = [
  [/unsupported driver \[oci8\]|could not find driver|call to undefined function oci_/i,
    'Server belum bisa terhubung ke Oracle',
    'Driver Oracle (oci8) belum terpasang di server tool ini. Hubungi admin untuk memasangnya — panduannya ada di README.'],
  [/ORA-01017|invalid username\/password/i,
    'Username atau password Oracle salah',
    'Periksa kembali akun Oracle di halaman Koneksi, lalu tes ulang.'],
  [/ORA-28000|account is locked/i,
    'Akun Oracle terkunci',
    'Minta DBA membuka kunci akun tersebut.'],
  [/ORA-12514|ORA-12505|service .* not known|SID .* not known/i,
    'Nama database Oracle tidak dikenal',
    'Service name / SID yang diisi tidak ditemukan di server. Tanyakan nilai yang benar ke DBA.'],
  [/ORA-12541|no listener|ORA-12170|ORA-12545|connection timed out|connection refused|could not connect to server|network is unreachable/i,
    'Server database tidak bisa dihubungi',
    'Periksa alamat host dan port, dan pastikan server ini boleh mengakses jaringan database tersebut.'],
  [/ORA-00942|table or view does not exist/i,
    'Tabel tidak ditemukan',
    'Periksa nama tabel dan skemanya (mis. APPS.NAMA_TABEL), dan pastikan akun Oracle punya hak SELECT pada tabel itu.'],
  [/ORA-00904|invalid identifier/i,
    'Ada nama kolom yang salah',
    'Salah satu kolom di query tidak ada di tabel. Periksa ejaannya.'],
  [/ORA-00933|ORA-00923|ORA-00936|syntax error/i,
    'Penulisan query belum benar',
    'Periksa tanda koma, kata kunci FROM, dan tanda kutip pada query.'],
  [/password authentication failed/i,
    'Username atau password PostgreSQL salah',
    'Periksa kembali akun database tujuan di halaman Koneksi.'],
  [/database ".*" does not exist/i,
    'Database PostgreSQL tidak ditemukan',
    'Nama database yang diisi tidak ada di server tersebut.'],
  [/permission denied/i,
    'Akun tidak punya izin yang cukup',
    'Akun database yang dipakai tidak boleh melakukan aksi ini. Minta admin database menambahkan hak aksesnya.'],
]

/** @returns {{ title: string, hint: string, raw: string } | null} */
export function friendlyError(message) {
  if (!message) return null
  for (const [pattern, title, hint] of ERROR_PATTERNS) {
    if (pattern.test(message)) return { title, hint, raw: message }
  }
  return null
}

/** Pesan validasi Laravel untuk field yang tidak punya tempat khusus. */
export function errorList(errors, known = []) {
  return Object.entries(errors ?? {})
    .filter(([key]) => !known.includes(key))
    .flatMap(([, messages]) => (Array.isArray(messages) ? messages : [messages]))
}
