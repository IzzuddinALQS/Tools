<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import Icon from '../Components/Icon.vue'
import Term from '../Components/Term.vue'
import EmptyState from '../Components/EmptyState.vue'
import WarningList from '../Components/WarningList.vue'
import { RUN_KIND, describeCron, formatNumber, timeAgo, verificationState } from '../lib/terms.js'

const props = defineProps({
  stats: { type: Object, required: true },
  recentRuns: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
  runtime: { type: Object, default: () => ({ scheduler: { known: false }, queue: { checked: false } }) },
})

/* ---------------- Status jadwal otomatis ---------------- */
const QUEUE_STUCK_AFTER = 120

const automation = computed(() => {
  const { scheduler = {}, queue = {} } = props.runtime ?? {}

  if (queue.checked && queue.oldest_age !== null && queue.oldest_age > QUEUE_STUCK_AFTER) {
    return {
      tone: 'red',
      title: 'Proses otomatis tertahan',
      text: `${queue.pending} proses menunggu dikerjakan sejak ${Math.round(queue.oldest_age / 60)} menit lalu. Pekerja antrean (queue worker) tidak berjalan.`,
      command: 'php artisan queue:work --queue=sync --tries=1 --timeout=3600',
    }
  }
  if (!scheduler.known) {
    return {
      tone: 'amber',
      title: 'Jadwal otomatis belum pernah berjalan',
      text: 'Data hanya diperbarui saat Anda menekan tombol. Agar jadwal berjalan, penjadwal dan pekerja antrean harus dinyalakan di server.',
      command: 'php artisan schedule:work   (dan di terminal lain)   php artisan queue:work --queue=sync',
    }
  }
  if (!scheduler.alive) {
    return {
      tone: 'red',
      title: 'Penjadwal berhenti',
      text: `Penjadwal terakhir terlihat ${timeAgo(scheduler.at)}. Jadwal ambil data tidak akan berjalan sampai dinyalakan lagi.`,
      command: 'php artisan schedule:work',
    }
  }
  return { tone: 'ok' }
})

const page = usePage()
const isAdmin = computed(() => page.props.auth?.is_admin === true)
const firstName = computed(() => (page.props.auth?.name ?? '').split(' ')[0])

/* ---------------- Panduan langkah awal ---------------- */
const firstSynced = computed(() => props.datasets.find((d) => d.last_success_at))
const hasCopy = computed(() => props.recentRuns.some((r) => r.kind === 'copy' && r.status === 'success'))

const steps = computed(() => [
  {
    title: 'Hubungkan sumber Oracle',
    text: 'Masukkan alamat Oracle dan akun yang hanya punya hak SELECT. Belum punya akses? Pakai "Data contoh" untuk mencoba.',
    done: props.stats.connections > 0,
    href: isAdmin.value ? '/drivers?tab=sources' : null,
    cta: 'Tambah sumber',
  },
  {
    title: 'Tambahkan aplikasi tujuan',
    text: 'Satu Database Penampung untuk menyimpan hasil, lalu satu Database Aplikasi untuk setiap aplikasi yang membaca datanya.',
    done: props.stats.targets > 0,
    href: isAdmin.value ? '/drivers?tab=targets' : null,
    cta: 'Tambah database',
  },
  {
    title: 'Buat dataset pertama',
    text: 'Tulis query SELECT, coba jalankan untuk melihat hasilnya, lalu simpan.',
    done: props.stats.datasets > 0,
    href: '/datasets/create',
    cta: 'Buat dataset',
  },
  {
    title: 'Ambil data untuk pertama kali',
    text: 'Buka dataset dan tekan "Ambil data sekarang". Selanjutnya berjalan otomatis sesuai jadwal.',
    done: Boolean(firstSynced.value),
    href: props.datasets[0] ? `/datasets/${props.datasets[0].id}` : null,
    cta: 'Buka dataset',
  },
  {
    title: 'Kirim ke Database Aplikasi',
    text: 'Salin tabel ke database yang dibaca aplikasi Anda. Bisa juga diatur otomatis per dataset.',
    done: hasCopy.value,
    href: '/copy',
    cta: 'Kirim data',
    optional: true,
  },
])

const doneCount = computed(() => steps.value.filter((s) => s.done).length)
const setupComplete = computed(() => steps.value.filter((s) => !s.optional).every((s) => s.done))
const showGuide = ref(!setupComplete.value)
const nextStep = computed(() => steps.value.findIndex((s) => !s.done))

const demoLoading = ref(false)
function startDemo() {
  demoLoading.value = true
  router.post('/contoh', {}, { onFinish: () => { demoLoading.value = false } })
}

/* ---------------- Kesehatan ---------------- */
const latestRunByDataset = computed(() => {
  const map = {}
  for (const run of props.recentRuns) {
    if (run.dataset && !map[run.dataset]) map[run.dataset] = run
  }
  return map
})

const attention = computed(() => props.datasets
  .map((dataset) => {
    const run = latestRunByDataset.value[dataset.name]
    if (run?.status === 'failed') return { dataset, reason: 'Proses terakhir gagal', run }
    if (run && verificationState(run.verification) === false) return { dataset, reason: 'Data tidak cocok setelah diperiksa', run }
    if (dataset.status === 'active' && !dataset.last_success_at) return { dataset, reason: 'Belum pernah berhasil mengambil data' }
    if (dataset.sync_mode === 'incremental' && !dataset.reconcile_cron) return { dataset, reason: 'Belum ada jadwal penyelarasan penuh' }
    return null
  })
  .filter(Boolean))

const lastSuccess = computed(() => props.datasets
  .map((d) => d.last_success_at)
  .filter(Boolean)
  .sort()
  .pop())

const summary = computed(() => [
  { label: 'Dataset aktif', value: `${props.stats.datasets_active} / ${props.stats.datasets}`, icon: 'table', href: '/datasets' },
  { label: 'Perlu perhatian', value: attention.value.length, icon: 'alert', tone: attention.value.length ? 'amber' : null },
  { label: 'Terakhir berhasil', value: lastSuccess.value ? timeAgo(lastSuccess.value) : '—', icon: 'clock', href: '/history' },
  { label: 'Database terhubung', value: props.stats.targets, icon: 'database', href: isAdmin.value ? '/drivers?tab=targets' : null },
])
</script>

<template>
  <Head title="Beranda" />

  <PageHeader :title="firstName ? `Halo, ${firstName}` : 'Beranda'">
    <template #subtitle>
      Ringkasan kondisi data Anda. Data dibaca dari Oracle — tidak pernah ditulis — lalu disimpan ke
      <Term term="pgA" /> dan dikirim ke <Term term="pgB" />.
    </template>

    <template #actions>
      <Link href="/datasets/create" class="btn-primary"><Icon name="plus" /> Dataset baru</Link>
    </template>

    <!-- Panduan langkah awal -->
    <section v-if="showGuide" class="surface overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-100 bg-gradient-to-r from-brand-50 to-surface px-6 py-5">
        <div>
          <p class="section-title">Mulai di sini</p>
          <p class="mt-0.5 text-sm text-ink-600">Ikuti langkah berikut untuk memindahkan data pertama Anda.</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="h-2 w-32 overflow-hidden rounded-full bg-ink-200">
            <div class="h-full rounded-full bg-brand-600 transition-all" :style="{ width: `${(doneCount / steps.length) * 100}%` }"></div>
          </div>
          <span class="text-sm font-medium text-ink-700">{{ doneCount }} dari {{ steps.length }}</span>
          <button v-if="setupComplete" type="button" class="btn-ghost btn-xs" @click="showGuide = false">Sembunyikan</button>
        </div>
      </div>

      <div v-if="isAdmin && stats.datasets === 0" class="flex flex-wrap items-center gap-4 border-b border-ink-100 bg-emerald-50/60 px-6 py-4">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"><Icon name="spark" :size="18" /></span>
        <p class="min-w-0 flex-1 text-sm text-ink-700">
          <strong class="text-ink-900">Ingin melihat cara kerjanya dulu?</strong>
          Buat contoh lengkap dalam satu klik memakai Oracle tiruan, tanpa perlu akses Oracle sungguhan.
        </p>
        <button type="button" class="btn-secondary btn-sm" :disabled="demoLoading" @click="startDemo">
          <Icon :name="demoLoading ? 'refresh' : 'play'" :size="16" :class="demoLoading ? 'animate-spin' : ''" />
          {{ demoLoading ? 'Menyiapkan…' : 'Coba dengan data contoh' }}
        </button>
      </div>

      <ol class="divide-y divide-ink-100">
        <li v-for="(step, index) in steps" :key="step.title" class="flex gap-4 px-6 py-5" :class="index === nextStep ? 'bg-brand-50/30' : ''">
          <span
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
            :class="step.done ? 'bg-emerald-100 text-emerald-700' : index === nextStep ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-500'"
          >
            <Icon v-if="step.done" name="check" :size="16" />
            <template v-else>{{ index + 1 }}</template>
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-semibold" :class="step.done ? 'text-ink-500 line-through decoration-ink-300' : 'text-ink-900'">
              {{ step.title }}
              <span v-if="step.optional" class="ml-1 text-xs font-normal text-ink-500 no-underline">(opsional)</span>
            </p>
            <p v-if="!step.done" class="mt-0.5 text-sm text-ink-600">{{ step.text }}</p>
            <p v-if="!step.done && !step.href && index < 2" class="mt-1 text-sm text-amber-800">
              Langkah ini dilakukan oleh admin. Hubungi admin tool ini.
            </p>
          </div>
          <Link
            v-if="!step.done && step.href"
            :href="step.href"
            class="self-center"
            :class="index === nextStep ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'"
          >
            {{ step.cta }} <Icon name="arrow" :size="16" />
          </Link>
        </li>
      </ol>
    </section>

    <button v-else-if="!setupComplete" type="button" class="btn-ghost btn-sm" @click="showGuide = true">
      <Icon name="help" /> Tampilkan panduan langkah awal
    </button>

    <!-- Status jadwal otomatis -->
    <WarningList
      v-if="automation.tone !== 'ok' && setupComplete"
      :tone="automation.tone === 'red' ? 'red' : 'amber'"
      :title="automation.title"
    >
      <p>{{ automation.text }}</p>
      <p v-if="isAdmin" class="mt-2">
        Jalankan di server: <span class="kbd break-all">{{ automation.command }}</span>
        <span class="mt-1 block text-[13px] opacity-80">Panduan lengkap ada di halaman <Link href="/bantuan#jadwal" class="font-semibold underline">Bantuan</Link>.</span>
      </p>
      <p v-else class="mt-2">Hubungi admin tool ini.</p>
    </WarningList>
    <p v-else-if="setupComplete" class="inline-flex items-center gap-2 text-sm text-ink-600">
      <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Jadwal otomatis berjalan normal.
    </p>

    <!-- Ringkasan -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <component
        :is="card.href ? Link : 'div'"
        v-for="card in summary"
        :key="card.label"
        :href="card.href"
        class="surface flex items-start gap-4 p-5 transition"
        :class="[card.href ? 'hover:border-brand-300 hover:shadow-md' : '', card.tone === 'amber' ? 'border-amber-300 bg-amber-50/50' : '']"
      >
        <span
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
          :class="card.tone === 'amber' ? 'bg-amber-100 text-amber-700' : 'bg-brand-50 text-brand-600'"
        >
          <Icon :name="card.icon" :size="20" />
        </span>
        <div class="min-w-0">
          <p class="text-sm text-ink-500">{{ card.label }}</p>
          <p class="mt-0.5 truncate text-2xl font-bold tabular-nums text-ink-900">{{ card.value }}</p>
        </div>
      </component>
    </div>

    <!-- Perlu perhatian -->
    <section v-if="datasets.length" class="space-y-4">
      <h2 class="section-title">Kondisi dataset</h2>

      <div v-if="attention.length === 0" class="surface flex items-center gap-4 p-5">
        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
          <Icon name="check-circle" :size="22" />
        </span>
        <div>
          <p class="font-semibold text-ink-900">Semua dataset dalam kondisi baik</p>
          <p class="text-sm text-ink-500">Tidak ada proses gagal atau data yang tidak cocok.</p>
        </div>
      </div>

      <ul v-else class="space-y-3">
        <li v-for="item in attention" :key="item.dataset.id">
          <Link :href="`/datasets/${item.dataset.id}`" class="surface flex items-center gap-4 border-amber-200 p-5 transition hover:border-amber-400">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
              <Icon name="alert" :size="20" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-ink-900">{{ item.dataset.name }}</p>
              <p class="text-sm text-amber-800">{{ item.reason }}</p>
            </div>
            <span class="hidden text-sm font-medium text-brand-700 sm:inline">Periksa</span>
            <span class="text-ink-400"><Icon name="chevron" /></span>
          </Link>
        </li>
      </ul>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Dataset</th>
              <th>Jadwal</th>
              <th>Terakhir berhasil</th>
              <th class="text-right">Jumlah baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="dataset in datasets" :key="dataset.id">
              <td>
                <Link :href="`/datasets/${dataset.id}`" class="font-semibold text-ink-900 hover:text-brand-700">{{ dataset.name }}</Link>
                <p class="mono text-xs text-ink-500">{{ dataset.destination }}</p>
              </td>
              <td class="text-sm whitespace-nowrap">
                <span v-if="dataset.status !== 'active'" class="badge-neutral">Dijeda</span>
                <template v-else>{{ describeCron(dataset.schedule_cron) }}</template>
              </td>
              <td class="text-sm whitespace-nowrap" :title="dataset.last_success_at">{{ timeAgo(dataset.last_success_at) }}</td>
              <td class="text-right tabular-nums">{{ formatNumber(dataset.last_row_count) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Aktivitas terakhir -->
    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="section-title">Aktivitas terakhir</h2>
        <Link href="/history" class="text-sm font-semibold text-brand-700 hover:underline">Lihat semua riwayat</Link>
      </div>

      <div class="table-wrap">
        <EmptyState
          v-if="recentRuns.length === 0"
          icon="history"
          title="Belum ada aktivitas"
          text="Setiap kali data diambil atau dikirim, catatannya muncul di sini."
        />

        <table v-else class="table">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Dataset</th>
              <th>Proses</th>
              <th>Hasil</th>
              <th><Term term="verify" /></th>
              <th class="text-right">Baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in recentRuns" :key="run.id">
              <td class="text-sm whitespace-nowrap text-ink-500" :title="run.finished_at">{{ timeAgo(run.finished_at) }}</td>
              <td>
                <Link :href="`/history/${run.id}`" class="font-semibold text-ink-900 hover:text-brand-700">{{ run.dataset || '—' }}</Link>
              </td>
              <td class="text-sm whitespace-nowrap">{{ RUN_KIND[run.kind] ?? run.kind }}</td>
              <td><StatusBadge :status="run.status" /></td>
              <td><VerifyBadge :matched="verificationState(run.verification)" compact /></td>
              <td class="text-right tabular-nums">{{ formatNumber(run.row_count) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
