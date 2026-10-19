<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import Icon from '../Components/Icon.vue'
import Term from '../Components/Term.vue'
import ErrorMessage from '../Components/ErrorMessage.vue'
import { RUN_KIND, RUN_TRIGGER, formatDate, formatDuration, formatNumber } from '../lib/terms.js'

const props = defineProps({
  run: { type: Object, required: true },
})

const kindLabel = computed(() => RUN_KIND[props.run.kind] ?? props.run.kind)

/* Ringkasan pemeriksaan dalam kalimat biasa; JSON lengkap tetap tersedia. */
const verdict = computed(() => {
  const v = props.run.verification
  if (!v) return null

  const source = v.source?.row_count
  const target = v.target?.row_count

  if (v.matched === true) {
    return {
      ok: true,
      title: 'Data cocok',
      text: source !== undefined
        ? `Sumber dan tujuan sama-sama berisi ${formatNumber(source)} baris, dan isinya identik baris per baris.`
        : 'Isi sumber dan tujuan identik baris per baris.',
    }
  }

  if (v.matched === false) {
    const parts = []
    if (source !== undefined && target !== undefined && source !== target) {
      parts.push(`Jumlah baris berbeda: sumber ${formatNumber(source)}, tujuan ${formatNumber(target)}.`)
    } else {
      parts.push('Jumlah baris sama, tetapi ada isi yang berbeda.')
    }
    parts.push('Coba jalankan ulang. Jika tetap berbeda, periksa apakah data di Oracle sedang berubah saat proses berjalan.')
    return { ok: false, title: 'Data tidak cocok', text: parts.join(' ') }
  }

  return null
})
</script>

<template>
  <Head :title="`Proses #${run.id}`" />

  <PageHeader :title="`${kindLabel}: ${run.dataset || '—'}`">
    <template #back>
      <Link href="/history" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 hover:text-ink-800">
        <Icon name="arrow-left" :size="16" /> Riwayat
      </Link>
    </template>
    <template #subtitle>
      Proses #{{ run.id }} · {{ RUN_TRIGGER[run.trigger] ?? run.trigger }} · {{ formatDate(run.started_at) }}
    </template>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="surface p-5">
        <p class="text-sm text-ink-500">Hasil</p>
        <div class="mt-2"><StatusBadge :status="run.status" /></div>
      </div>
      <div class="surface p-5">
        <p class="text-sm text-ink-500">Jumlah baris</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-ink-900">{{ formatNumber(run.row_count) }}</p>
      </div>
      <div class="surface p-5">
        <p class="text-sm text-ink-500">Durasi</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-ink-900">{{ formatDuration(run.duration_ms) }}</p>
      </div>
      <div class="surface p-5">
        <p class="text-sm text-ink-500"><Term term="verify" /></p>
        <p class="mt-1 text-2xl font-bold" :class="verdict ? (verdict.ok ? 'text-emerald-700' : 'text-red-700') : 'text-ink-400'">
          {{ verdict ? (verdict.ok ? 'Cocok' : 'Tidak cocok') : '—' }}
        </p>
      </div>
    </div>

    <div v-if="run.error_message" class="flex gap-4 rounded-2xl border border-red-200 bg-red-50 p-5">
      <span class="text-red-600"><Icon name="alert" :size="22" /></span>
      <div class="min-w-0">
        <p class="font-semibold text-red-900">Penyebab kegagalan</p>
        <div class="mt-1 text-sm leading-relaxed text-red-800"><ErrorMessage :message="run.error_message" /></div>
        <p v-if="run.error_code" class="mt-2 font-mono text-xs text-red-700">Kode: {{ run.error_code }}</p>
      </div>
    </div>

    <div v-if="verdict" class="flex gap-4 rounded-2xl border p-5" :class="verdict.ok ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
      <span :class="verdict.ok ? 'text-emerald-600' : 'text-red-600'"><Icon :name="verdict.ok ? 'check-circle' : 'alert'" :size="22" /></span>
      <div>
        <p class="font-semibold" :class="verdict.ok ? 'text-emerald-900' : 'text-red-900'">{{ verdict.title }}</p>
        <p class="mt-1 text-sm" :class="verdict.ok ? 'text-emerald-800' : 'text-red-800'">{{ verdict.text }}</p>
      </div>
    </div>

    <div class="surface p-6">
      <h2 class="section-title">Rincian</h2>
      <dl class="mt-4 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <dt class="text-ink-500">Sumber</dt>
          <dd class="mt-0.5 font-medium text-ink-800">{{ run.connection || '—' }}</dd>
        </div>
        <div>
          <dt class="text-ink-500">Tujuan</dt>
          <dd class="mt-0.5 font-medium text-ink-800">{{ run.target || '—' }}</dd>
        </div>
        <div>
          <dt class="text-ink-500">Dijalankan oleh</dt>
          <dd class="mt-0.5 font-medium text-ink-800">{{ run.triggered_by || 'Jadwal otomatis' }}</dd>
        </div>
        <div>
          <dt class="text-ink-500">Mulai</dt>
          <dd class="mt-0.5 font-medium text-ink-800">{{ formatDate(run.started_at) }}</dd>
        </div>
        <div>
          <dt class="text-ink-500">Selesai</dt>
          <dd class="mt-0.5 font-medium text-ink-800">{{ formatDate(run.finished_at) }}</dd>
        </div>
      </dl>
    </div>

    <details v-if="run.sql_text" class="group surface" open>
      <summary class="flex cursor-pointer items-center justify-between px-6 py-4">
        <span class="section-title">Query yang dijalankan</span>
        <span class="text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
      </summary>
      <pre class="mono mx-6 mb-6 overflow-auto rounded-xl bg-ink-900 p-4 leading-relaxed text-ink-100">{{ run.sql_text }}</pre>
    </details>

    <details v-if="run.verification || run.sql_hash" class="group surface">
      <summary class="flex cursor-pointer items-center justify-between px-6 py-4">
        <span>
          <span class="section-title">Detail teknis</span>
          <span class="ml-2 text-sm text-ink-500">untuk keperluan pelacakan masalah</span>
        </span>
        <span class="text-ink-400 transition group-open:rotate-180"><Icon name="chevron-down" /></span>
      </summary>
      <div class="space-y-3 px-6 pb-6">
        <p v-if="run.sql_hash" class="text-sm text-ink-600">Sidik jari query: <span class="mono break-all text-ink-800">{{ run.sql_hash }}</span></p>
        <p class="text-sm text-ink-600">
          Sidik jari (hash) sisi Oracle dihitung saat membaca baris tanpa query tambahan; sisi PostgreSQL dihitung dengan cara yang sama.
        </p>
        <pre v-if="run.verification" class="mono overflow-auto rounded-xl bg-ink-900 p-4 text-xs leading-relaxed text-ink-100">{{ JSON.stringify(run.verification, null, 2) }}</pre>
      </div>
    </details>
  </PageHeader>
</template>
