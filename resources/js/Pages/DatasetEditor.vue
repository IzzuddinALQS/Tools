<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import CodeEditor from '../Components/CodeEditor.vue'
import PreviewTable from '../Components/PreviewTable.vue'
import WarningList from '../Components/WarningList.vue'
import StatusBadge from '../Components/StatusBadge.vue'

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

const form = useForm({
  name: props.dataset?.name ?? '',
  description: props.dataset?.description ?? '',
  connection_id: props.dataset?.connection_id ?? (props.connections[0]?.id ?? null),
  target_id: props.dataset?.target_id ?? (props.targets.find((t) => t.kind === 'pg_a')?.id ?? null),
  sql: props.dataset?.sql ?? 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE\nFROM APPS.PO_HEADERS',
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

const preview = ref(null)
const previewing = ref(false)
const previewError = ref(null)
const running = ref(false)

async function runPreview() {
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
      previewError.value = payload.message || payload.errors?.sql?.[0] || 'Preview gagal.'
      preview.value = null
      return
    }

    preview.value = payload.preview
  } catch (error) {
    previewError.value = 'Tidak dapat menghubungi server: ' + error.message
  } finally {
    previewing.value = false
  }
}

function save() {
  if (isEdit) {
    form.put(`/datasets/${props.dataset.id}`)
  } else {
    form.post('/datasets')
  }
}

function runNow(mode) {
  if (!isEdit) return
  running.value = true
  form.transform((data) => ({ mode })).post(`/datasets/${props.dataset.id}/run`, {
    preserveScroll: true,
    onFinish: () => {
      running.value = false
      form.resetTransform()
    },
  })
}

function toggleKeyColumn(name) {
  const index = form.key_columns.indexOf(name)

  if (index === -1) {
    form.key_columns.push(name)
  } else {
    form.key_columns.splice(index, 1)
  }
}
</script>

<template>
  <Head :title="isEdit ? `Dataset ${dataset.name}` : 'Dataset baru'" />

  <div class="space-y-6">
    <!-- Judul -->
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <h1 class="page-title">{{ isEdit ? dataset.name : 'Dataset baru' }}</h1>
        <p class="mt-1 text-sm text-ink-500">
          Tulis satu query SELECT. Kalau membaca satu tabel Oracle, nama tabel dan kolom di PG A
          dibuat identik dengan Oracle.
        </p>
      </div>

      <div v-if="isEdit" class="flex flex-wrap gap-2">
        <button type="button" class="btn-primary" :disabled="running" @click="runNow(dataset.sync_mode)">
          {{ running ? 'Menjalankan…' : 'Jalankan sinkronisasi' }}
        </button>
        <button v-if="dataset.sync_mode === 'incremental'" type="button" class="btn-secondary"
                :disabled="running" @click="runNow('full')">
          Rekonsiliasi full
        </button>
      </div>
    </div>

    <WarningList
      v-if="form.sync_mode === 'incremental'"
      title="Mode incremental: baris yang DIHAPUS di Oracle tidak terdeteksi"
      :warnings="[
        'Dataset ini wajib disertai jadwal rekonsiliasi full berkala agar hasilnya kembali identik.',
        `Jadwal rekonsiliasi saat ini: ${form.reconcile_cron || 'BELUM DISET'}.`,
      ]"
    />

    <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="save">
      <!-- Kolom kiri -->
      <div class="space-y-4 lg:col-span-2">
        <div class="surface p-5">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="eyebrow">Nama dataset</span>
              <input v-model="form.name" required class="input mt-1.5" placeholder="po-headers">
              <span v-if="form.errors.name" class="hint text-red-600">{{ form.errors.name }}</span>
            </label>

            <label class="block">
              <span class="eyebrow">Koneksi sumber</span>
              <select v-model="form.connection_id" required class="input mt-1.5">
                <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }} — {{ c.driver_label }}</option>
              </select>
            </label>

            <label class="block">
              <span class="eyebrow">Target PostgreSQL A</span>
              <select v-model="form.target_id" required class="input mt-1.5">
                <option v-for="t in targets.filter((x) => x.kind === 'pg_a')" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
              <span class="hint">Salinan ke PG B dibuat lewat halaman Copy, bukan di sini.</span>
            </label>

            <label class="block">
              <span class="eyebrow">Mode sync</span>
              <select v-model="form.sync_mode" class="input mt-1.5">
                <option v-for="m in defaults.sync_modes" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </label>

            <label class="block sm:col-span-2">
              <span class="eyebrow">Keterangan</span>
              <input v-model="form.description" class="input mt-1.5" placeholder="opsional">
            </label>
          </div>
        </div>

        <div class="surface p-5">
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-semibold text-ink-900">Query SELECT</span>
            <button type="button" class="btn-secondary btn-sm" :disabled="previewing" @click="runPreview">
              {{ previewing ? 'Menjalankan…' : `Preview (${defaults.preview_rows} baris)` }}
            </button>
          </div>

          <CodeEditor v-model="form.sql" />

          <p v-if="form.errors.sql" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-700">
            {{ form.errors.sql }}
          </p>
          <p v-else class="mt-3 text-xs text-ink-400">
            Preview hanya menampilkan {{ defaults.preview_rows }} baris pertama dan tidak menyimpan apa pun.
          </p>
        </div>

        <div v-if="previewError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {{ previewError }}
        </div>

        <template v-if="preview">
          <div class="surface flex flex-wrap items-center gap-3 px-5 py-4">
            <span :class="preview.identity_mode ? 'badge-success' : 'badge-warning'">
              {{ preview.identity_mode ? 'Nama identik Oracle' : 'Nama tabel tujuan sendiri' }}
            </span>
            <span class="mono text-xs text-ink-600">{{ preview.qualified_table }}</span>
            <span v-if="preview.source_table" class="text-xs text-ink-400">dari {{ preview.source_table }}</span>
            <span class="ml-auto text-xs text-ink-400">{{ preview.row_count_shown }} baris ditampilkan</span>
          </div>

          <WarningList v-if="preview.warnings?.length" title="Catatan" :warnings="preview.warnings" tone="info" />

          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Kolom</th>
                  <th>Tipe Oracle</th>
                  <th>Tipe PostgreSQL</th>
                  <th>Sumber tipe</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="column in preview.columns" :key="column.name" class="align-top">
                  <td class="mono font-medium text-ink-900">{{ column.name }}</td>
                  <td class="mono text-xs text-ink-500">{{ column.oracle_type || '—' }}</td>
                  <td>
                    <code class="rounded bg-ink-100 px-1.5 py-0.5 font-mono text-[11.5px] text-ink-800">{{ column.pg_type }}</code>
                  </td>
                  <td class="text-xs">
                    <span v-if="column.from_metadata" class="text-emerald-700">metadata Oracle</span>
                    <span v-else class="text-amber-700">perlu konfirmasi</span>
                    <span v-if="column.overridden" class="ml-1 text-ink-400">(diubah admin)</span>
                    <ul v-if="column.warnings?.length" class="mt-1 list-disc pl-4 text-amber-700">
                      <li v-for="(w, i) in column.warnings" :key="i">{{ w }}</li>
                    </ul>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <PreviewTable :columns="preview.columns" :rows="preview.rows" />
        </template>
      </div>

      <!-- Kolom kanan -->
      <div class="space-y-4">
        <div class="surface space-y-4 p-5">
          <h2 class="text-sm font-semibold text-ink-900">Batas & jadwal</h2>

          <label class="block">
            <span class="eyebrow">Jadwal sync (cron)</span>
            <input v-model="form.schedule_cron" class="input-sm mono mt-1.5" placeholder="0 * * * *">
          </label>
          <label class="block">
            <span class="eyebrow">Jadwal rekonsiliasi full</span>
            <input v-model="form.reconcile_cron" class="input-sm mono mt-1.5" placeholder="0 2 * * *">
          </label>

          <div class="grid grid-cols-3 gap-2">
            <label class="block">
              <span class="eyebrow">Timeout (dtk)</span>
              <input v-model.number="form.timeout_seconds" type="number" min="1" class="input-sm mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">Maks baris</span>
              <input v-model.number="form.max_rows" type="number" min="1" class="input-sm mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">Batch</span>
              <input v-model.number="form.batch_size" type="number" min="1" class="input-sm mt-1.5">
            </label>
          </div>
        </div>

        <div v-if="form.sync_mode === 'incremental'" class="surface space-y-4 border-amber-200 p-5">
          <h2 class="text-sm font-semibold text-ink-900">Kunci watermark</h2>

          <label class="block">
            <span class="eyebrow">Kolom watermark</span>
            <input v-model="form.watermark_column" class="input-sm mono mt-1.5" placeholder="LAST_UPDATE_DATE">
          </label>
          <label class="block">
            <span class="eyebrow">Tipe watermark</span>
            <input v-model="form.watermark_type" class="input-sm mono mt-1.5" placeholder="TIMESTAMP">
          </label>

          <div>
            <span class="eyebrow">Kolom kunci (untuk upsert)</span>
            <div v-if="preview?.columns?.length" class="mt-2 flex flex-wrap gap-1.5">
              <button v-for="column in preview.columns" :key="column.name" type="button"
                      class="badge px-2 py-1 font-mono"
                      :class="form.key_columns.includes(column.name)
                        ? 'bg-ink-900 text-white ring-ink-900'
                        : 'bg-white text-ink-600 ring-ink-300 hover:bg-ink-50'"
                      @click="toggleKeyColumn(column.name)">
                {{ column.name }}
              </button>
            </div>
            <p v-else class="mt-1.5 text-xs text-ink-400">Jalankan Preview dulu untuk melihat daftar kolom.</p>
            <span v-if="form.errors.key_columns" class="hint text-red-600">{{ form.errors.key_columns }}</span>
          </div>
        </div>

        <div class="surface space-y-3 p-5">
          <h2 class="text-sm font-semibold text-ink-900">Copy otomatis ke PG B</h2>
          <p class="text-xs leading-relaxed text-ink-500">
            Setiap sinkronisasi sukses otomatis disalin ke target di bawah.
          </p>
          <label v-for="t in copyTargets" :key="t.id" class="flex items-start gap-2.5 text-sm">
            <input type="checkbox" :value="t.id" v-model="form.auto_copy_to"
                   class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
            <span class="text-ink-700">
              {{ t.name }}
              <span class="text-xs text-ink-400">({{ t.app_name }})</span>
            </span>
          </label>
          <p v-if="copyTargets.length === 0" class="text-xs text-ink-400">
            Belum ada PG B. Tambahkan lewat halaman Driver.
          </p>
        </div>

        <label v-if="isEdit" class="block surface p-5">
          <span class="eyebrow">Catatan perubahan</span>
          <input v-model="form.note" class="input mt-1.5" placeholder="mis. tambah kolom STATUS_CODE">
          <span class="hint">Tersimpan bersama versi baru.</span>
        </label>

        <button type="submit" class="btn-primary w-full" :disabled="form.processing">
          {{ isEdit ? 'Simpan perubahan' : 'Simpan ke PostgreSQL A' }}
        </button>
      </div>
    </form>

    <!-- Riwayat versi -->
    <section v-if="isEdit && versions.length" class="space-y-3">
      <h2 class="eyebrow">Riwayat versi</h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Versi</th>
              <th>Waktu</th>
              <th>Oleh</th>
              <th>Jenis</th>
              <th>Catatan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="version in versions.slice().reverse()" :key="version.id">
              <td class="font-medium tabular-nums">v{{ version.version_no }}</td>
              <td class="text-xs text-ink-500">{{ version.created_at }}</td>
              <td class="text-xs">{{ version.changed_by || '—' }}</td>
              <td class="text-xs text-ink-500">{{ version.change_type }}</td>
              <td class="text-xs text-ink-600">{{ version.note || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Eksekusi terakhir -->
    <section v-if="isEdit && recentRuns.length" class="space-y-3">
      <h2 class="eyebrow">Eksekusi terakhir</h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Mulai</th>
              <th>Jenis</th>
              <th>Status</th>
              <th class="text-right">Baris</th>
              <th class="text-right">Durasi</th>
              <th>Pesan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in recentRuns" :key="run.id">
              <td class="whitespace-nowrap text-xs text-ink-500">{{ run.started_at }}</td>
              <td class="text-xs">{{ run.kind }} · {{ run.trigger }}</td>
              <td><StatusBadge :status="run.status" /></td>
              <td class="text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
              <td class="text-right text-xs text-ink-500">{{ run.duration_ms ?? '—' }} ms</td>
              <td class="text-xs text-red-600">{{ run.error_message || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
