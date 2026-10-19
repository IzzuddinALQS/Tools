<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import Icon from '../Components/Icon.vue'
import { TERMS } from '../lib/terms.js'

const page = usePage()
const isAdmin = computed(() => page.props.auth?.is_admin === true)

const sections = [
  { id: 'alur', label: 'Cara kerja' },
  { id: 'mulai', label: 'Langkah pertama' },
  { id: 'istilah', label: 'Daftar istilah' },
  { id: 'pemeriksaan', label: 'Membaca hasil pemeriksaan' },
  { id: 'jadwal', label: 'Jadwal otomatis' },
  { id: 'masalah', label: 'Jika ada masalah' },
]

const glossary = ['source', 'pgA', 'pgB', 'dataset', 'sync', 'copy', 'reconcile', 'fullMode', 'incrementalMode',
  'watermark', 'keyColumns', 'verify', 'readonly', 'pause', 'apiToken', 'mock']

const problems = [
  {
    q: 'Tes koneksi Oracle gagal: "Server belum bisa terhubung ke Oracle"',
    a: 'Driver Oracle (ekstensi PHP oci8 + Oracle Instant Client) belum terpasang di server. Admin dapat mengikuti README bagian "Instalasi oci8", lalu memeriksa hasilnya dengan perintah php artisan ods:cek-oracle.',
  },
  {
    q: 'Coba jalankan berhasil, tapi muncul pesan "wajib memberi nama tabel tujuan sendiri"',
    a: 'Query Anda menggabungkan beberapa tabel, memakai alias, atau fungsi (COUNT, SUM, dll.). Isi "Ubah nama tabel tujuan" di langkah 2 dengan skema dan nama tabel pilihan Anda.',
  },
  {
    q: 'Hasil pemeriksaan "Data tidak cocok"',
    a: 'Biasanya karena data di Oracle berubah saat proses berjalan. Jalankan ulang. Jika tetap tidak cocok, buka Detail proses → Detail teknis dan kirimkan isinya ke admin.',
  },
  {
    q: 'Jadwal tidak berjalan, data tidak pernah diperbarui otomatis',
    a: 'Penjadwal dan pekerja antrean belum dinyalakan di server. Lihat bagian Jadwal otomatis di atas. Beranda juga menampilkan peringatan bila keduanya mati.',
  },
  {
    q: 'Baris yang dihapus di Oracle masih ada di PostgreSQL',
    a: 'Dataset memakai cara "Hanya data yang berubah". Baris yang dihapus baru hilang saat Penyelarasan penuh berjalan. Pastikan jadwal penyelarasan diisi, atau tekan "Penyelarasan penuh" di halaman dataset.',
  },
  {
    q: 'Saya ingin menghentikan semuanya sekarang juga',
    a: 'Tekan "Hentikan semua" di menu samping. Tidak ada data yang ditulis sampai Anda menekan "Lanjutkan semua".',
  },
]
</script>

<template>
  <Head title="Bantuan" />

  <PageHeader title="Bantuan">
    <template #subtitle>
      Panduan singkat memakai Oracle Data Sync, dari langkah pertama sampai menangani masalah.
    </template>

    <div class="grid gap-8 lg:grid-cols-[13rem_1fr]">
      <!-- Daftar isi -->
      <nav class="hidden lg:block" aria-label="Daftar isi">
        <ul class="sticky top-24 space-y-1 text-sm">
          <li v-for="s in sections" :key="s.id">
            <a :href="`#${s.id}`" class="block rounded-lg px-3 py-1.5 text-ink-600 hover:bg-ink-100 hover:text-ink-900">{{ s.label }}</a>
          </li>
        </ul>
      </nav>

      <div class="min-w-0 space-y-10">
        <!-- Cara kerja -->
        <section id="alur" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Cara kerja</h2>
          <p class="text-ink-700">
            Tool ini menyalin data dari Oracle ke PostgreSQL. Oracle <strong>hanya dibaca</strong> — tidak pernah ditulis, diubah, atau dihapus.
          </p>
          <div class="grid items-center gap-3 sm:grid-cols-[1fr_auto_1fr_auto_1fr]">
            <div class="surface p-4 text-center">
              <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-ink-100 text-ink-700"><Icon name="database" :size="20" /></span>
              <p class="mt-2 font-semibold text-ink-900">Oracle</p>
              <p class="text-xs text-ink-500">sumber, hanya dibaca</p>
            </div>
            <span class="mx-auto rotate-90 text-ink-400 sm:rotate-0"><Icon name="arrow" :size="22" /></span>
            <div class="surface border-brand-200 p-4 text-center">
              <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="database" :size="20" /></span>
              <p class="mt-2 font-semibold text-ink-900">{{ TERMS.pgA.label }}</p>
              <p class="text-xs text-ink-500">menyimpan hasil query</p>
            </div>
            <span class="mx-auto rotate-90 text-ink-400 sm:rotate-0"><Icon name="arrow" :size="22" /></span>
            <div class="surface p-4 text-center">
              <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><Icon name="send" :size="20" /></span>
              <p class="mt-2 font-semibold text-ink-900">{{ TERMS.pgB.label }}</p>
              <p class="text-xs text-ink-500">dibaca aplikasi Anda</p>
            </div>
          </div>
          <p class="text-sm text-ink-600">
            Mengapa dua tahap? Database Penampung menjadi salinan induk. Jika Database Aplikasi rusak atau terhapus,
            cukup kirim ulang dari Penampung tanpa membebani Oracle lagi.
          </p>
        </section>

        <!-- Langkah pertama -->
        <section id="mulai" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Langkah pertama</h2>
          <ol class="space-y-3">
            <li v-for="(step, i) in [
              ['Tambahkan Database Penampung dan Database Aplikasi', 'Halaman Koneksi → Aplikasi tujuan (khusus admin).'],
              ['Tambahkan sumber Oracle', 'Halaman Koneksi → Sumber koneksi. Gunakan akun Oracle yang hanya punya hak SELECT. Belum punya akses? Pilih jenis Data contoh.'],
              ['Buat dataset', 'Halaman Dataset → Dataset baru. Tulis query SELECT, tekan Coba jalankan, lalu simpan.'],
              ['Ambil data', 'Di halaman dataset, tekan Ambil data sekarang. Selanjutnya berjalan otomatis sesuai jadwal.'],
              ['Kirim ke aplikasi', 'Halaman Kirim ke Aplikasi, atau centang aplikasi tujuan di langkah 4 dataset agar otomatis.'],
            ]" :key="i" class="flex gap-4">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ i + 1 }}</span>
              <div>
                <p class="font-semibold text-ink-900">{{ step[0] }}</p>
                <p class="text-sm text-ink-600">{{ step[1] }}</p>
              </div>
            </li>
          </ol>
          <p class="text-sm text-ink-600">Panduan yang sama dengan tanda centang progres juga tampil di <Link href="/" class="font-semibold text-brand-700 underline">Beranda</Link>.</p>
        </section>

        <!-- Istilah -->
        <section id="istilah" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Daftar istilah</h2>
          <dl class="surface divide-y divide-ink-100">
            <div v-for="key in glossary" :key="key" class="grid gap-1 px-5 py-4 sm:grid-cols-[14rem_1fr] sm:gap-6">
              <dt class="font-semibold text-ink-900">
                {{ TERMS[key].label }}
                <span v-if="TERMS[key].tech" class="block text-xs font-normal text-ink-500">{{ TERMS[key].tech }}</span>
              </dt>
              <dd class="text-sm text-ink-700">{{ TERMS[key].help }}</dd>
            </div>
          </dl>
        </section>

        <!-- Pemeriksaan -->
        <section id="pemeriksaan" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Membaca hasil pemeriksaan</h2>
          <p class="text-ink-700">
            Setiap kali data dipindahkan, isi sumber dan tujuan dibandingkan memakai sidik jari (hash) dari seluruh baris.
          </p>
          <div class="grid gap-3 sm:grid-cols-3">
            <div class="surface p-4">
              <span class="badge-success">✓ Data cocok</span>
              <p class="mt-2 text-sm text-ink-600">Jumlah baris dan seluruh isinya identik. Tidak perlu tindakan.</p>
            </div>
            <div class="surface p-4">
              <span class="badge-danger">✗ Data tidak cocok</span>
              <p class="mt-2 text-sm text-ink-600">Ada perbedaan. Jalankan ulang; lihat bagian "Jika ada masalah".</p>
            </div>
            <div class="surface p-4">
              <span class="badge-neutral">– Belum diperiksa</span>
              <p class="mt-2 text-sm text-ink-600">Proses gagal atau dihentikan sebelum sempat diperiksa.</p>
            </div>
          </div>
        </section>

        <!-- Jadwal -->
        <section id="jadwal" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Jadwal otomatis</h2>
          <p class="text-ink-700">
            Agar data diperbarui sesuai jadwal, dua proses harus terus berjalan di server: <strong>penjadwal</strong>
            (memeriksa jadwal setiap menit) dan <strong>pekerja antrean</strong> (mengerjakan prosesnya).
            Beranda menampilkan peringatan bila salah satunya mati.
          </p>
          <div v-if="isAdmin" class="space-y-3">
            <p class="text-sm font-semibold text-ink-800">Untuk mencoba di komputer sendiri, buka dua terminal:</p>
            <pre class="mono overflow-auto rounded-xl bg-slate-900 p-4 leading-relaxed text-slate-100">php artisan schedule:work
php artisan queue:work --queue=sync --tries=1 --timeout=3600</pre>
            <p class="text-sm font-semibold text-ink-800">Di server Linux, pasang cron dan jalankan worker sebagai service:</p>
            <pre class="mono overflow-auto rounded-xl bg-slate-900 p-4 leading-relaxed text-slate-100">* * * * * cd /lokasi/oracle-data-sync &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
            <p class="text-sm text-ink-600">Detail konfigurasi service (systemd / supervisor) ada di README.</p>
          </div>
          <p v-else class="text-sm text-ink-600">Pengaturan ini dilakukan oleh admin server.</p>
        </section>

        <!-- Masalah -->
        <section id="masalah" class="scroll-mt-24 space-y-4">
          <h2 class="section-title">Jika ada masalah</h2>
          <div class="space-y-2">
            <details v-for="item in problems" :key="item.q" class="group surface">
              <summary class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4 font-semibold text-ink-900">
                {{ item.q }}
                <span class="shrink-0 text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
              </summary>
              <p class="px-5 pb-4 text-sm leading-relaxed text-ink-700">{{ item.a }}</p>
            </details>
          </div>
          <div v-if="isAdmin" class="surface-quiet p-5 text-sm text-ink-700">
            <p class="font-semibold text-ink-900">Untuk admin: memeriksa kesiapan Oracle</p>
            <p class="mt-1">Perintah berikut memeriksa langkah demi langkah dan menjelaskan apa yang harus diperbaiki:</p>
            <pre class="mono mt-3 overflow-auto rounded-xl bg-slate-900 p-4 text-slate-100">php artisan ods:cek-oracle --tabel=SKEMA.NAMA_TABEL</pre>
          </div>
        </section>
      </div>
    </div>
  </PageHeader>
</template>
