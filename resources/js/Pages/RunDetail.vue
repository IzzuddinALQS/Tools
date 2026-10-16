<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'

defineProps({
  run: { type: Object, required: true },
})
</script>

<template>
  <Head :title="`Eksekusi #${run.id}`" />

  <PageHeader :title="`Eksekusi #${run.id}`" :subtitle="`${run.dataset || '—'} · ${run.kind} · ${run.trigger}`">
    <template #actions>
      <Link href="/history" class="btn-secondary">Kembali ke riwayat</Link>
    </template>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="surface px-5 py-4">
        <p class="eyebrow">Status</p>
        <div class="mt-2"><StatusBadge :status="run.status" /></div>
      </div>
      <div class="surface px-5 py-4">
        <p class="eyebrow">Jumlah baris</p>
        <p class="mt-1.5 text-3xl font-semibold tabular-nums text-ink-900">{{ run.row_count ?? '—' }}</p>
      </div>
      <div class="surface px-5 py-4">
        <p class="eyebrow">Durasi</p>
        <p class="mt-1.5 text-3xl font-semibold tabular-nums text-ink-900">
          {{ run.duration_ms ?? '—' }}<span class="text-base font-normal text-ink-400"> ms</span>
        </p>
      </div>
      <div class="surface px-5 py-4">
        <p class="eyebrow">Verifikasi</p>
        <div class="mt-2">
          <VerifyBadge :matched="run.verification?.matched ?? null" :detail="run.verification" />
        </div>
      </div>
    </div>

    <div v-if="run.error_message" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm">
      <p class="font-mono text-xs text-red-500">{{ run.error_code }}</p>
      <p class="mt-1 text-red-800">{{ run.error_message }}</p>
    </div>

    <div class="surface p-5">
      <h2 class="text-sm font-semibold text-ink-900">Rincian</h2>
      <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
        <div>
          <dt class="eyebrow">Sumber</dt>
          <dd class="mt-0.5 text-ink-700">{{ run.connection || '—' }}</dd>
        </div>
        <div>
          <dt class="eyebrow">Target</dt>
          <dd class="mt-0.5 text-ink-700">{{ run.target || '—' }}</dd>
        </div>
        <div>
          <dt class="eyebrow">Dipicu oleh</dt>
          <dd class="mt-0.5 text-ink-700">{{ run.triggered_by || 'sistem' }}</dd>
        </div>
        <div>
          <dt class="eyebrow">Mulai</dt>
          <dd class="mt-0.5 text-ink-700">{{ run.started_at || '—' }}</dd>
        </div>
        <div>
          <dt class="eyebrow">Selesai</dt>
          <dd class="mt-0.5 text-ink-700">{{ run.finished_at || '—' }}</dd>
        </div>
        <div>
          <dt class="eyebrow">Hash SQL</dt>
          <dd class="mono mt-0.5 truncate text-ink-700">{{ run.sql_hash || '—' }}</dd>
        </div>
      </dl>
    </div>

    <div v-if="run.verification" class="surface p-5">
      <h2 class="text-sm font-semibold text-ink-900">Detail verifikasi</h2>
      <p class="mt-1 text-xs leading-relaxed text-ink-500">
        Hash sisi Oracle dihitung saat membaca baris, tanpa query tambahan. Hash sisi PostgreSQL
        dihitung dengan kode kanonikalisasi yang sama.
      </p>
      <pre class="mono mt-3 overflow-auto rounded-lg bg-ink-900 p-4 text-[11.5px] leading-relaxed text-ink-100">{{ JSON.stringify(run.verification, null, 2) }}</pre>
    </div>

    <div v-if="run.sql_text" class="surface p-5">
      <h2 class="text-sm font-semibold text-ink-900">SQL yang dieksekusi</h2>
      <pre class="mono mt-3 overflow-auto rounded-lg bg-ink-900 p-4 text-[11.5px] leading-relaxed text-ink-100">{{ run.sql_text }}</pre>
    </div>
  </PageHeader>
</template>
