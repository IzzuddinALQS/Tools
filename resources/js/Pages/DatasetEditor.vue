<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import CodeEditor from '../Components/CodeEditor.vue'
import PreviewTable from '../Components/PreviewTable.vue'
import WarningList from '../Components/WarningList.vue'

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

const sqlErrors = form.errors?.sql

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
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold tracking-tight">
          {{ isEdit ? dataset.name : 'Dataset baru' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          Tulis satu query SELECT. Kalau membaca satu tabel Oracle, nama tabel dan kolom di PG A
          dibuat identik dengan Oracle.
        </p>
      </div>

      <div v-if="isEdit" class="flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
          :disabled="running"
          @click="runNow(dataset.sync_mode)"
        >
          {{ running ? 'Menjalankan…' : 'Jalankan sinkronisasi' }}
        </button>
        <button
          v-if="dataset.sync_mode === 'incremental'"
          type="button"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 disabled:opacity-50"
          :disabled="running"
          @click="runNow('full')"
        >
          Rekonsiliasi full
        </button>
      </div>
    </header>

    <WarningList
      v-if="form.sync_mode === 'incremental'"
      title="Mode incremental: baris yang DIHAPUS di Oracle tidak terdeteksi"
      :warnings="[
        'Dataset ini wajib disertai jadwal rekonsiliasi full berkala agar hasilnya kembali identik.',
        `Jadwal rekonsiliasi saat ini: ${form.reconcile_cron || 'BELUM DISET'}.`,
      ]"
    />

    <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="save">
      <div class="space-y-4 lg:col-span-2">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="text-sm font-medium text-slate-700">Nama dataset</span>
              <input v-model="form.name" required
                     class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <span v-if="form.errors.name" class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Koneksi sumber</span>
              <select v-model="form.connection_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option v-for="connection in connections" :key="connection.id" :value="connection.id">
                  {{ connection.name }} — {{ connection.driver_label }}
                </option>
              </select>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Target PostgreSQL A</span>
              <select v-model="form.target_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option v-for="target in targets.filter((t) => t.kind === 'pg_a')" :key="target.id" :value="target.id">
                  {{ target.name }}
                </option>
              </select>
              <span class="mt-1 block text-xs text-slate-500">
                Salinan ke PG B dibuat lewat halaman Copy, bukan di sini.
              </span>
            </label>

            <label class="block">
              <span class="text-sm font-medium text-slate-700">Mode sync</span>
              <select v-model="form.sync_mode" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option v-for="mode in defaults.sync_modes" :key="mode.value" :value="mode.value">
                  {{ mode.label }}
                </option>
              </select>
            </label>
          </div>

          <label class="mt-4 block">
            <span class="text-sm font-medium text-slate-700">Keterangan</span>
            <input v-model="form.description"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </label>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <div class="mb-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-700">Query SELECT</span>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50"
                    :disabled="previewing" @click="runPreview">
              {{ previewing ? 'Menjalankan…' : `Preview (${defaults.preview_rows} baris)` }}
            </button>
          </div>

          <CodeEditor v-model="form.sql" />

          <p v-if="form.errors.sql" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
            {{ form.errors.sql }}
          </p>
          <p class="mt-2 text-xs text-slate-500">
            Preview hanya menampilkan {{ defaults.preview_rows }} baris pertama dan tidak menyimpan apa pun.
          </p>
        </div>

        <div v-if="previewError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {{ previewError }}
        </div>

        <div v-if="preview" class="space-y-3">
          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-center gap-3 text-sm">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="preview.identity_mode ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                {{ preview.identity_mode ? 'Nama identik Oracle' : 'Nama tabel tujuan sendiri' }}
              </span>
              <span class="font-mono text-xs text-slate-600">{{ preview.qualified_table }}</span>
              <span v-if="preview.source_table" class="text-xs text-slate-500">dari {{ preview.source_table }}</span>
              <span class="ml-auto text-xs text-slate-500">{{ preview.row_count_shown }} baris ditampilkan</span>
            </div>
          </div>

          <WarningList v-if="preview.warnings?.length" title="Catatan tipe" :warnings="preview.warnings" />

          <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
              <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th class="px-3 py-2">Kolom</th>
                  <th class="px-3 py-2">Tipe Oracle</th>
                  <th class="px-3 py-2">Tipe PostgreSQL</th>
                  <th class="px-3 py-2">Sumber</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="column in preview.columns" :key="column.name" class="border-t border-slate-100 align-top">
                  <td class="px-3 py-2 font-mono text-xs">{{ column.name }}</td>
                  <td class="px-3 py-2 text-xs text-slate-600">{{ column.oracle_type || '—' }}</td>
                  <td class="px-3 py-2">
                    <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ column.pg_type }}</code>
                  </td>
                  <td class="px-3 py-2 text-xs">
                    <span v-if="column.from_metadata" class="text-emerald-700">metadata Oracle</span>
                    <span v-else class="text-amber-700">perlu konfirmasi</span>
                    <span v-if="column.overridden" class="ml-1 text-slate-400">(diubah admin)</span>
                    <ul v-if="column.warnings?.length" class="mt-1 list-disc pl-4 text-amber-700">
                      <li v-for="(warning, i) in column.warnings" :key="i">{{ warning }}</li>
                    </ul>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <PreviewTable :columns="preview.columns" :rows="preview.rows" />
        </div>
      </div>

      <div class="space-y-4">
        <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="text-sm font-semibold">Batas & jadwal</h2>

          <label class="block">
            <span class="text-sm text-slate-600">Jadwal sync (cron)</span>
            <input v-model="form.schedule_cron" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 font-mono text-xs">
          </label>

          <label class="block">
            <span class="text-sm text-slate-600">Jadwal rekonsiliasi full</span>
            <input v-model="form.reconcile_cron" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 font-mono text-xs">
          </label>

          <div class="grid grid-cols-3 gap-2">
            <label class="block">
              <span class="text-xs text-slate-600">Timeout (detik)</span>
              <input v-model.number="form.timeout_seconds" type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
            </label>
            <label class="block">
              <span class="text-xs text-slate-600">Maks baris</span>
              <input v-model.number="form.max_rows" type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
            </label>
            <label class="block">
              <span class="text-xs text-slate-600">Batch</span>
              <input v-model.number="form.batch_size" type="number" min="1" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
            </label>
          </div>
        </div>

        <div v-if="form.sync_mode === 'incremental'" class="space-y-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
          <h2 class="text-sm font-semibold">Kunci watermark</h2>

          <label class="block">
            <span class="text-sm text-slate-700">Kolom watermark</span>
            <input v-model="form.watermark_column" placeholder="LAST_UPDATE_DATE"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 font-mono text-xs">
          </label>

          <label class="block">
            <span class="text-sm text-slate-700">Tipe watermark</span>
            <input v-model="form.watermark_type" placeholder="TIMESTAMP"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 font-mono text-xs">
          </label>

          <div>
            <span class="text-sm text-slate-700">Kolom kunci (untuk upsert)</span>
            <div v-if="preview?.columns?.length" class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="column in preview.columns"
                :key="column.name"
                type="button"
                class="rounded-full border px-2 py-0.5 font-mono text-xs"
                :class="form.key_columns.includes(column.name)
                  ? 'border-slate-900 bg-slate-900 text-white'
                  : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
                @click="toggleKeyColumn(column.name)"
              >
                {{ column.name }}
              </button>
            </div>
            <p v-else class="mt-1 text-xs text-slate-600">Jalankan Preview dulu untuk melihat daftar kolom.</p>
            <span v-if="form.errors.key_columns" class="mt-1 block text-xs text-red-600">{{ form.errors.key_columns }}</span>
          </div>
        </div>

        <div class="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="text-sm font-semibold">Copy otomatis ke PG B</h2>
          <p class="text-xs text-slate-500">Setiap sinkronisasi sukses akan otomatis disalin ke target di bawah.</p>

          <label v-for="target in copyTargets" :key="target.id" class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              :value="target.id"
              v-model="form.auto_copy_to"
              class="rounded border-slate-300"
            >
            <span>{{ target.name }} <span class="text-xs text-slate-500">({{ target.app_name }})</span></span>
          </label>

          <p v-if="copyTargets.length === 0" class="text-xs text-slate-500">
            Belum ada PG B. Tambahkan lewat halaman Driver.
          </p>
        </div>

        <label v-if="isEdit" class="block rounded-lg border border-slate-200 bg-white p-4">
          <span class="text-sm font-medium text-slate-700">Catatan perubahan</span>
          <input v-model="form.note" placeholder="mis. tambah kolom STATUS_CODE"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <span class="mt-1 block text-xs text-slate-500">Tersimpan bersama versi baru.</span>
        </label>

        <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                :disabled="form.processing">
          {{ isEdit ? 'Simpan perubahan' : 'Simpan ke PostgreSQL A' }}
        </button>
      </div>
    </form>

    <section v-if="isEdit && versions.length" class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Riwayat versi</h2>
      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Versi</th>
              <th class="px-3 py-2">Waktu</th>
              <th class="px-3 py-2">Oleh</th>
              <th class="px-3 py-2">Jenis</th>
              <th class="px-3 py-2">Catatan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="version in versions.slice().reverse()" :key="version.id" class="border-t border-slate-100">
              <td class="px-3 py-2 tabular-nums">v{{ version.version_no }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ version.created_at }}</td>
              <td class="px-3 py-2 text-xs">{{ version.changed_by || '—' }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ version.change_type }}</td>
              <td class="px-3 py-2 text-xs text-slate-600">{{ version.note || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="isEdit && recentRuns.length" class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Eksekusi terakhir</h2>
      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <tbody>
            <tr v-for="run in recentRuns" :key="run.id" class="border-b border-slate-100 last:border-0">
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.started_at }}</td>
              <td class="px-3 py-2">{{ run.kind }} / {{ run.trigger }}</td>
              <td class="px-3 py-2">{{ run.row_count ?? '—' }} baris</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.duration_ms ?? '—' }} ms</td>
              <td class="px-3 py-2 text-xs text-red-600">{{ run.error_message || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
