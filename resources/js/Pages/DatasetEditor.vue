<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import CodeEditor from '../Components/CodeEditor.vue'
import PreviewTable from '../Components/PreviewTable.vue'
import WarningList from '../Components/WarningList.vue'
import ErrorMessage from '../Components/ErrorMessage.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import SchedulePicker from '../Components/SchedulePicker.vue'
import Field from '../Components/Field.vue'
import Term from '../Components/Term.vue'
import Icon from '../Components/Icon.vue'
import { confirm } from '../lib/confirm.js'
import {
  RUN_KIND, RUN_TRIGGER, TERMS, describeCron, errorList, formatDuration, formatNumber,
  timeAgo, verificationState,
} from '../lib/terms.js'

const props = defineProps({
  dataset: { type: Object, default: null },
  versions: { type: Array, default: () => [] },
  connections: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  copyTargets: { type: Array, default: () => [] },
  defaults: { type: Object, required: true },
  recentRuns: { type: Array, default: () => [] },
})

const isEdit = Boolean(props.dataset)

/* Contoh query ini cocok dengan katalog "Data contoh" bawaan. */
const SAMPLE_SQL = 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE\nFROM APPS.PO_HEADERS'

const firstConnection = props.connections[0]
const pgATargets = computed(() => props.targets.filter((t) => t.kind === 'pg_a'))

const form = useForm({
  name: props.dataset?.name ?? '',
  description: props.dataset?.description ?? '',
  connection_id: props.dataset?.connection_id ?? (firstConnection?.id ?? null),
  target_id: props.dataset?.target_id ?? (props.targets.find((t) => t.kind === 'pg_a')?.id ?? null),
  sql: props.dataset?.sql ?? (firstConnection?.driver === 'mock' ? SAMPLE_SQL : ''),
  destination_schema: props.dataset?.destination_schema ?? '',
  destination_table: props.dataset?.destination_table ?? '',
  column_overrides: props.dataset?.column_overrides ?? {},
  sync_mode: props.dataset?.sync_mode ?? 'full',
  watermark_column: props.dataset?.watermark_column ?? '',
  watermark_type: props.dataset?.watermark_type ?? 'TIMESTAMP',
  key_columns: props.dataset?.key_columns ?? [],
  schedule_cron: props.dataset?.schedule_cron ?? props.defaults.schedule_cron,
  reconcile_cron: props.dataset?.reconcile_cron ?? props.defaults.reconcile_cron,
  timeout_seconds: props.dataset?.timeout_seconds ?? props.defaults.timeout_seconds,
  max_rows: props.dataset?.max_rows ?? props.defaults.max_rows,
  batch_size: props.dataset?.batch_size ?? props.defaults.batch_size,
  auto_copy_to: props.dataset?.auto_copy_to ?? [],
  note: '',
})

const selectedConnection = computed(() => props.connections.find((c) => c.id === Number(form.connection_id)))

/* ---------------- Coba jalankan (preview) ---------------- */
const preview = ref(null)
const previewing = ref(false)
const previewError = ref(null)
const previewedSql = ref(null)
const previewStale = computed(() => preview.value && previewedSql.value !== form.sql)

async function runPreview() {
  if (!form.sql.trim() || previewing.value) return
  previewError.value = null
  previewing.value = true

  try {
    const response = await fetch('/datasets/preview', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        connection_id: form.connection_id,
        sql: form.sql,
        destination_schema: form.destination_schema || null,
        destination_table: form.destination_table || null,
        column_overrides: form.column_overrides,
      }),
    })

    const payload = await response.json()

    if (!response.ok || !payload.ok) {
      previewError.value = payload.message || payload.errors?.sql?.[0] || payload.errors?.connection_id?.[0] || 'Query gagal dijalankan.'
      preview.value = null
      return
    }

    preview.value = payload.preview
    previewedSql.value = form.sql
  } catch (error) {
    previewError.value = 'Tidak dapat menghubungi server: ' + error.message
  } finally {
    previewing.value = false
  }
}

function useSample() {
  form.sql = SAMPLE_SQL
}

/* ---------------- Simpan & aksi ---------------- */
function save() {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      form.note = ''
      form.defaults()
    },
  }

  if (isEdit) {
    form.put(`/datasets/${props.dataset.id}`, options)
  } else {
    form.post('/datasets', options)
  }
}

const running = ref(false)

async function runNow(mode) {
  if (!isEdit) return

  if (form.isDirty) {
    const ok = await confirm({
      title: 'Ada perubahan yang belum disimpan',
      message: 'Proses akan memakai versi yang TERSIMPAN, bukan perubahan yang sedang Anda ketik. Simpan dulu jika ingin memakai perubahan terbaru.',
      confirmLabel: 'Tetap jalankan',
    })
    if (!ok) return
  }

  running.value = true
  router.post(`/datasets/${props.dataset.id}/run`, { mode }, {
    preserveScroll: true,
    onFinish: () => { running.value = false },
  })
}

function toggleStatus() {
  router.post(`/datasets/${props.dataset.id}/toggle`, {}, { preserveScroll: true })
}

async function destroy() {
  const ok = await confirm({
    title: `Hapus dataset "${props.dataset.name}"?`,
    message: 'Definisi dataset, jadwal, dan riwayat versinya akan dihapus.',
    details: ['Tabel yang sudah ada di Database Penampung TIDAK ikut terhapus.', 'Tindakan ini tidak bisa dibatalkan.'],
    confirmLabel: 'Hapus dataset',
    tone: 'danger',
  })

  if (ok) {
    form.defaults()
    router.delete(`/datasets/${props.dataset.id}`)
  }
}

function toggleKeyColumn(name) {
  const index = form.key_columns.indexOf(name)
  if (index === -1) form.key_columns.push(name)
  else form.key_columns.splice(index, 1)
}

/* ---------------- Peringatan perubahan belum disimpan ---------------- */
let removeBefore = null

function onBeforeUnload(event) {
  if (form.isDirty) {
    event.preventDefault()
    event.returnValue = ''
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', onBeforeUnload)
  removeBefore = router.on('before', (event) => {
    const visit = event.detail.visit
    if (visit.method !== 'get' || !form.isDirty) return true
    return window.confirm('Perubahan pada dataset ini belum disimpan. Tinggalkan halaman?')
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
  removeBefore?.()
})

const otherErrors = computed(() => errorList(form.errors, [
  'name', 'sql', 'connection_id', 'target_id', 'key_columns', 'watermark_column',
  'schedule_cron', 'reconcile_cron', 'destination_schema', 'destination_table',
]))

const showAdvanced = ref(Boolean(props.dataset?.destination_table))
</script>

<template>
  <Head :title="isEdit ? dataset.name : 'Dataset baru'" />

  <div class="space-y-8 pb-24">
    <!-- Judul -->
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0 max-w-2xl">
        <Link href="/datasets" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 hover:text-ink-800">
          <Icon name="arrow-left" :size="16" /> Semua dataset
        </Link>
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="page-title">{{ isEdit ? dataset.name : 'Dataset baru' }}</h1>
          <span v-if="isEdit" :class="dataset.status === 'active' ? 'badge-success' : 'badge-neutral'">
            {{ dataset.status === 'active' ? 'Aktif' : 'Jadwal dijeda' }}
          </span>
        </div>
        <p class="mt-2 text-ink-500">
          <template v-if="isEdit">
            {{ describeCron(dataset.schedule_cron) }} · terakhir berhasil {{ timeAgo(dataset.last_success_at) }}
            <template v-if="dataset.last_row_count !== null"> · {{ formatNumber(dataset.last_row_count) }} baris</template>
          </template>
          <template v-else>Tiga langkah: tulis query, lihat contoh hasilnya, lalu atur nama dan jadwal.</template>
        </p>
      </div>

      <div v-if="isEdit" class="flex flex-wrap gap-2">
        <button type="button" class="btn-primary" :disabled="running" @click="runNow(dataset.sync_mode)">
          <Icon :name="running ? 'refresh' : 'play'" :class="running ? 'animate-spin' : ''" />
          {{ running ? 'Sedang mengambil…' : 'Ambil data sekarang' }}
        </button>
        <button v-if="dataset.sync_mode === 'incremental'" type="button" class="btn-secondary" :disabled="running" @click="runNow('full')">
          Penyelarasan penuh
        </button>
      </div>
    </div>

    <form id="dataset-form" class="space-y-6" @submit.prevent="save">
      <!-- ============ Langkah 1: Query ============ -->
      <section class="surface p-6 sm:p-8">
        <div class="flex items-start gap-4">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">1</span>
          <div class="min-w-0 flex-1 space-y-5">
            <div>
              <h2 class="section-title">Data apa yang ingin diambil?</h2>
              <p class="mt-1 text-sm text-ink-500">
                Tulis <strong>satu</strong> query <span class="kbd">SELECT</span>. Query lain (INSERT, UPDATE, DELETE, dll.) otomatis ditolak.
              </p>
            </div>

            <Field term="source" label="Ambil dari koneksi":error="form.errors.connection_id">
              <select v-if="connections.length" v-model="form.connection_id" required class="input">
                <option v-for="c in connections" :key="c.id" :value="c.id">
                  {{ c.name }} — {{ c.driver === 'mock' ? TERMS.mock.label : 'Oracle' }}
                </option>
              </select>
              <WarningList v-else title="Belum ada sumber Oracle">
                Admin perlu menambahkan sumber di halaman <Link href="/drivers?tab=sources" class="font-semibold underline">Koneksi</Link> terlebih dahulu.
              </WarningList>
            </Field>

            <div>
              <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <span class="label">Query SELECT</span>
                <button v-if="selectedConnection?.driver === 'mock' && form.sql !== SAMPLE_SQL" type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="useSample">
                  Pakai contoh query
                </button>
              </div>

              <CodeEditor v-model="form.sql" height="14rem" @run="runPreview" />

              <p v-if="form.errors.sql" class="error-text">{{ form.errors.sql }}</p>

              <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" :disabled="previewing || !form.sql.trim() || !form.connection_id" @click="runPreview">
                  <Icon :name="previewing ? 'refresh' : 'play'" :class="previewing ? 'animate-spin' : ''" />
                  {{ previewing ? 'Menjalankan…' : 'Coba jalankan' }}
                </button>
                <span class="text-[13px] text-ink-500">
                  Menampilkan {{ defaults.preview_rows }} baris pertama, tidak menyimpan apa pun. Pintasan: <span class="kbd">Ctrl</span> + <span class="kbd">Enter</span>
                </span>
              </div>
            </div>

            <WarningList v-if="previewError" tone="red" title="Query tidak bisa dijalankan"><ErrorMessage :message="previewError" /></WarningList>

            <!-- Hasil coba jalankan -->
            <div v-if="preview" class="space-y-4 rounded-2xl border border-ink-200 bg-ink-50/60 p-4 sm:p-5">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-800">
                  <Icon name="check-circle" /> Query berhasil
                </span>
                <span class="text-sm text-ink-600">{{ preview.columns.length }} kolom · {{ preview.row_count_shown }} baris contoh</span>
                <span v-if="previewStale" class="badge-warning">Query sudah diubah — coba jalankan lagi</span>
              </div>

              <p class="text-sm text-ink-700">
                Akan disimpan sebagai tabel <span class="mono font-semibold text-ink-900">{{ preview.qualified_table }}</span>
                <template v-if="preview.source_table"> (nama sama dengan tabel Oracle {{ preview.source_table }})</template>.
              </p>

              <WarningList v-if="preview.warnings?.length" title="Catatan" :warnings="preview.warnings" tone="info" />

              <PreviewTable :columns="preview.columns" :rows="preview.rows" />

              <details class="group rounded-xl border border-ink-200 bg-surface">
                <summary class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-semibold text-ink-800">
                  Tipe kolom di PostgreSQL
                  <span class="text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
                </summary>
                <div class="overflow-x-auto border-t border-ink-100">
                  <table class="table">
                    <thead>
                      <tr><th>Kolom</th><th>Tipe di Oracle</th><th>Tipe di PostgreSQL</th><th>Keterangan</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="column in preview.columns" :key="column.name" class="align-top">
                        <td class="mono font-medium text-ink-900">{{ column.name }}</td>
                        <td class="mono text-ink-600">{{ column.oracle_type || '—' }}</td>
                        <td><span class="kbd">{{ column.pg_type }}</span></td>
                        <td class="text-[13px]">
                          <span v-if="column.from_metadata" class="text-emerald-800">Sesuai definisi Oracle</span>
                          <span v-else class="text-amber-800">Ditebak dari isi data — mohon periksa</span>
                          <span v-if="column.overridden" class="ml-1 text-ink-500">(diubah admin)</span>
                          <ul v-if="column.warnings?.length" class="mt-1 list-disc pl-4 text-amber-800">
                            <li v-for="(w, i) in column.warnings" :key="i">{{ w }}</li>
                          </ul>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </details>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ Langkah 2: Nama & tujuan ============ -->
      <section class="surface p-6 sm:p-8">
        <div class="flex items-start gap-4">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">2</span>
          <div class="min-w-0 flex-1 space-y-5">
            <h2 class="section-title">Beri nama dan tentukan tujuan</h2>

            <div class="grid gap-5 sm:grid-cols-2">
              <Field label="Nama dataset" hint="Nama pendek yang mudah dikenali, mis. pesanan-pembelian." :error="form.errors.name">
                <input v-model="form.name" required class="input" placeholder="pesanan-pembelian">
              </Field>

              <Field term="pgA" label="Simpan ke" :error="form.errors.target_id">
                <select v-if="pgATargets.length > 1" v-model="form.target_id" required class="input">
                  <option v-for="t in pgATargets" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <div v-else-if="pgATargets.length === 1" class="input flex items-center gap-2 bg-ink-50 text-ink-700">
                  <Icon name="database" :size="16" /> {{ pgATargets[0].name }}
                </div>
                <WarningList v-else title="Belum ada Database Penampung">
                  Admin perlu menambahkannya di halaman <Link href="/drivers?tab=targets" class="font-semibold underline">Koneksi</Link>.
                </WarningList>
              </Field>

              <Field label="Keterangan" optional class="sm:col-span-2">
                <input v-model="form.description" class="input" placeholder="mis. Daftar PO untuk aplikasi gudang">
              </Field>
            </div>

            <div>
              <button type="button" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" @click="showAdvanced = !showAdvanced">
                <Icon :name="showAdvanced ? 'chevron-down' : 'chevron'" :size="16" />
                Ubah nama tabel tujuan
              </button>
              <div v-if="showAdvanced" class="mt-4 grid gap-5 rounded-xl bg-ink-50 p-4 sm:grid-cols-2">
                <p class="text-sm text-ink-600 sm:col-span-2">
                  Kosongkan agar nama skema dan tabel dibuat sama dengan tabel Oracle (disarankan). Wajib diisi jika query menggabungkan beberapa tabel.
                </p>
                <Field label="Skema" optional :error="form.errors.destination_schema">
                  <input v-model="form.destination_schema" class="input mono" placeholder="sama dengan Oracle">
                </Field>
                <Field label="Nama tabel" optional :error="form.errors.destination_table">
                  <input v-model="form.destination_table" class="input mono" placeholder="sama dengan Oracle">
                </Field>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ Langkah 3: Jadwal ============ -->
      <section class="surface p-6 sm:p-8">
        <div class="flex items-start gap-4">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">3</span>
          <div class="min-w-0 flex-1 space-y-6">
            <h2 class="section-title">Kapan dan bagaimana data diperbarui?</h2>

            <Field label="Jadwal ambil data" tag="div" :error="form.errors.schedule_cron">
              <SchedulePicker v-model="form.schedule_cron" />
            </Field>

            <div>
              <span class="label">Cara mengambil data</span>
              <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <label class="choice">
                  <input v-model="form.sync_mode" type="radio" value="full" class="mt-1 accent-brand-600">
                  <span>
                    <span class="block font-semibold text-ink-900">{{ TERMS.fullMode.label }} <span class="badge-success ml-1">Disarankan</span></span>
                    <span class="mt-1 block text-sm text-ink-600">{{ TERMS.fullMode.help }}</span>
                  </span>
                </label>
                <label class="choice">
                  <input v-model="form.sync_mode" type="radio" value="incremental" class="mt-1 accent-brand-600">
                  <span>
                    <span class="block font-semibold text-ink-900">{{ TERMS.incrementalMode.label }}</span>
                    <span class="mt-1 block text-sm text-ink-600">Lebih cepat untuk tabel besar. Butuh kolom waktu perubahan dan kolom ID unik.</span>
                  </span>
                </label>
              </div>
            </div>

            <div v-if="form.sync_mode === 'incremental'" class="space-y-5 rounded-2xl border border-amber-200 bg-amber-50/50 p-5">
              <WarningList
                title="Baris yang dihapus di Oracle tidak langsung terhapus"
                :warnings="['Karena itu wajib ada jadwal penyelarasan penuh berkala, misalnya setiap malam, agar datanya kembali identik.']"
              />

              <div class="grid gap-5 sm:grid-cols-2">
                <Field term="watermark" :error="form.errors.watermark_column">
                  <select v-if="preview?.columns?.length" v-model="form.watermark_column" class="input mono">
                    <option value="">Pilih kolom…</option>
                    <option v-for="column in preview.columns" :key="column.name" :value="column.name">{{ column.name }}</option>
                  </select>
                  <input v-else v-model="form.watermark_column" class="input mono" placeholder="LAST_UPDATE_DATE">
                </Field>
                <Field label="Jenis isi kolom tersebut">
                  <select v-model="form.watermark_type" class="input">
                    <option value="TIMESTAMP">Tanggal & jam (TIMESTAMP)</option>
                    <option value="DATE">Tanggal (DATE)</option>
                    <option value="NUMBER">Angka berurutan (NUMBER)</option>
                  </select>
                </Field>
              </div>

              <Field term="keyColumns" tag="div" :error="form.errors.key_columns">
                <div v-if="preview?.columns?.length" class="flex flex-wrap gap-2">
                  <button
                    v-for="column in preview.columns"
                    :key="column.name"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 font-mono text-[13px] transition"
                    :class="form.key_columns.includes(column.name)
                      ? 'border-brand-600 bg-brand-600 text-white'
                      : 'border-ink-300 bg-surface text-ink-700 hover:border-ink-400'"
                    :aria-pressed="form.key_columns.includes(column.name)"
                    @click="toggleKeyColumn(column.name)"
                  >
                    <Icon v-if="form.key_columns.includes(column.name)" name="check" :size="14" />
                    {{ column.name }}
                  </button>
                </div>
                <p v-else class="text-sm text-ink-600">
                  <template v-if="form.key_columns.length">Terpilih: <span class="mono">{{ form.key_columns.join(', ') }}</span>. </template>
                  Tekan <strong>Coba jalankan</strong> di langkah 1 untuk memilih dari daftar kolom.
                </p>
              </Field>

              <Field term="reconcile" label="Jadwal penyelarasan penuh" tag="div" :error="form.errors.reconcile_cron">
                <SchedulePicker v-model="form.reconcile_cron" :allow-manual="false" />
              </Field>
            </div>

            <details class="group rounded-xl border border-ink-200">
              <summary class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-semibold text-ink-800">
                Pengaturan lanjutan (batas waktu & ukuran)
                <span class="text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
              </summary>
              <div class="grid gap-5 border-t border-ink-100 p-4 sm:grid-cols-3">
                <Field label="Batas waktu (detik)" hint="Proses dihentikan jika lebih lama dari ini." :error="form.errors.timeout_seconds">
                  <input v-model.number="form.timeout_seconds" type="number" min="1" class="input">
                </Field>
                <Field label="Batas jumlah baris" hint="Pengaman agar query tidak mengambil data berlebihan." :error="form.errors.max_rows">
                  <input v-model.number="form.max_rows" type="number" min="1" class="input">
                </Field>
                <Field label="Baris per kiriman" hint="Data ditulis bertahap sebanyak ini per kali." :error="form.errors.batch_size">
                  <input v-model.number="form.batch_size" type="number" min="1" class="input">
                </Field>
              </div>
            </details>
          </div>
        </div>
      </section>

      <!-- ============ Langkah 4: Kirim otomatis ============ -->
      <section class="surface p-6 sm:p-8">
        <div class="flex items-start gap-4">
          <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-200 text-sm font-bold text-ink-700">4</span>
          <div class="min-w-0 flex-1 space-y-4">
            <div>
              <h2 class="section-title">Kirim otomatis ke aplikasi <span class="text-sm font-normal text-ink-500">(opsional)</span></h2>
              <p class="mt-1 text-sm text-ink-500">
                Setiap kali data berhasil diambil, tabelnya langsung disalin ke <Term term="pgB" /> yang dipilih.
              </p>
            </div>

            <div v-if="copyTargets.length" class="grid gap-3 sm:grid-cols-2">
              <label v-for="t in copyTargets" :key="t.id" class="choice items-center">
                <input v-model="form.auto_copy_to" type="checkbox" :value="t.id" class="checkbox">
                <span>
                  <span class="block font-semibold text-ink-900">{{ t.app_name }}</span>
                  <span class="block text-sm text-ink-500">{{ t.name }}</span>
                </span>
              </label>
            </div>
            <p v-else class="rounded-xl bg-ink-50 px-4 py-3 text-sm text-ink-600">
              Belum ada Database Aplikasi. Admin bisa menambahkannya di halaman Koneksi.
            </p>
          </div>
        </div>
      </section>

      <WarningList v-if="otherErrors.length" tone="red" title="Belum bisa disimpan" :warnings="otherErrors" />
    </form>

    <!-- Riwayat proses -->
    <section v-if="isEdit" class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="section-title">Proses terakhir</h2>
        <Link :href="`/history?dataset_id=${dataset.id}`" class="text-sm font-semibold text-brand-700 hover:underline">Lihat semua</Link>
      </div>
      <div class="table-wrap">
        <p v-if="recentRuns.length === 0" class="px-6 py-10 text-center text-sm text-ink-500">
          Belum pernah dijalankan. Tekan <strong>Ambil data sekarang</strong> untuk mencoba.
        </p>
        <table v-else class="table">
          <thead>
            <tr><th>Waktu</th><th>Proses</th><th>Hasil</th><th>Pemeriksaan</th><th class="text-right">Baris</th><th class="text-right">Durasi</th></tr>
          </thead>
          <tbody>
            <tr v-for="run in recentRuns" :key="run.id" class="align-top">
              <td class="text-sm whitespace-nowrap">
                <Link :href="`/history/${run.id}`" class="font-medium text-ink-900 hover:text-brand-700" :title="run.started_at">{{ timeAgo(run.started_at) }}</Link>
              </td>
              <td class="text-sm">{{ RUN_KIND[run.kind] ?? run.kind }} <span class="text-ink-500">· {{ RUN_TRIGGER[run.trigger] ?? run.trigger }}</span></td>
              <td>
                <StatusBadge :status="run.status" />
                <div v-if="run.error_message" class="mt-1 max-w-sm text-[13px] text-red-700"><ErrorMessage :message="run.error_message" /></div>
              </td>
              <td><VerifyBadge :matched="verificationState(run.verification)" compact /></td>
              <td class="text-right tabular-nums">{{ formatNumber(run.row_count) }}</td>
              <td class="text-right text-sm whitespace-nowrap text-ink-600">{{ formatDuration(run.duration_ms) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Riwayat versi -->
    <details v-if="isEdit && versions.length" class="group surface">
      <summary class="flex cursor-pointer items-center justify-between px-6 py-4">
        <span class="section-title">Riwayat perubahan <span class="text-sm font-normal text-ink-500">({{ versions.length }} versi)</span></span>
        <span class="text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
      </summary>
      <ol class="divide-y divide-ink-100 border-t border-ink-100">
        <li v-for="version in versions.slice().reverse()" :key="version.id" class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-6 py-3 text-sm">
          <span class="badge-neutral tabular-nums">v{{ version.version_no }}</span>
          <span class="text-ink-800">{{ version.note || version.change_type }}</span>
          <span class="ml-auto text-ink-500">{{ version.changed_by || 'sistem' }} · {{ timeAgo(version.created_at) }}</span>
        </li>
      </ol>
    </details>

    <!-- Zona berbahaya -->
    <section v-if="isEdit" class="surface flex flex-wrap items-center justify-between gap-4 border-red-200 p-6">
      <div>
        <p class="font-semibold text-ink-900">Jeda atau hapus dataset</p>
        <p class="text-sm text-ink-500">Menjeda menghentikan jadwal otomatis saja. Menghapus tidak menghapus tabel yang sudah ada.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary" @click="toggleStatus">
          <Icon :name="dataset.status === 'active' ? 'pause' : 'play'" />
          {{ dataset.status === 'active' ? 'Jeda jadwal' : 'Aktifkan jadwal' }}
        </button>
        <button type="button" class="btn-danger" @click="destroy"><Icon name="trash" /> Hapus</button>
      </div>
    </section>
  </div>

  <!-- Bar simpan -->
  <div class="fixed inset-x-0 bottom-0 z-20 border-t border-ink-200 bg-surface/95 backdrop-blur lg:left-64">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-10">
      <span v-if="form.isDirty" class="inline-flex items-center gap-2 text-sm font-medium text-amber-800">
        <span class="h-2 w-2 rounded-full bg-amber-500"></span> Ada perubahan yang belum disimpan
      </span>
      <span v-else-if="isEdit" class="text-sm text-ink-500">Semua perubahan tersimpan</span>
      <span v-else-if="!preview" class="text-sm text-ink-500">Disarankan <strong>Coba jalankan</strong> dulu sebelum menyimpan.</span>

      <input
        v-if="isEdit && form.isDirty"
        v-model="form.note"
        class="input-sm w-full sm:w-64"
        placeholder="Catatan perubahan (opsional)"
        aria-label="Catatan perubahan"
      >

      <button type="submit" form="dataset-form" class="btn-primary ml-auto" :disabled="form.processing || (isEdit && !form.isDirty)">
        <Icon name="check" />
        {{ form.processing ? 'Menyimpan…' : isEdit ? 'Simpan perubahan' : 'Simpan dataset' }}
      </button>
    </div>
  </div>
</template>
