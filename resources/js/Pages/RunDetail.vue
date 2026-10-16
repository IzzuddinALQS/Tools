<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'

const props = defineProps({
  run: { type: Object, required: true },
})
</script>

<template>
  <Head :title="`Run #${run.id}`" />

  <PageHeader :title="`Eksekusi #${run.id}`">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">{{ run.dataset || '—' }} · {{ run.kind }} · {{ run.trigger }}</p>
    </template>

    <template #actions>
      <Link href="/history" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
        Kembali ke riwayat
      </Link>
    </template>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs uppercase tracking-wide text-slate-500">Status</p>
        <div class="mt-1"><StatusBadge :status="run.status" /></div>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs uppercase tracking-wide text-slate-500">Jumlah baris</p>
        <p class="mt-1 text-2xl font-semibold">{{ run.row_count ?? '—' }}</p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs uppercase tracking-wide text-slate-500">Durasi</p>
        <p class="mt-1 text-2xl font-semibold">{{ run.duration_ms ?? '—' }}<span class="text-sm font-normal"> ms</span></p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs uppercase tracking-wide text-slate-500">Verifikasi</p>
        <div class="mt-2">
          <VerifyBadge
            :matched="run.verification?.matched ?? null"
            :detail="run.verification"
          />
        </div>
      </div>
    </div>

    <div v-if="run.error_message" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      <p class="font-medium">{{ run.error_code }}</p>
      <p class="mt-1">{{ run.error_message }}</p>
    </div>

    <div v-if="run.verification" class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="text-sm font-semibold">Detail verifikasi</h2>
      <p class="mt-1 text-xs text-slate-500">
        Hash sisi Oracle dihitung saat membaca baris, tanpa query tambahan. Hash sisi PostgreSQL
        dihitung dengan kode kanonikalisasi yang sama.
      </p>
      <pre class="mt-3 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ JSON.stringify(run.verification, null, 2) }}</pre>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="text-sm font-semibold">Rincian</h2>
      <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
        <div><dt class="text-xs text-slate-400">Sumber</dt><dd>{{ run.connection || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">Target</dt><dd>{{ run.target || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">Dipicu oleh</dt><dd>{{ run.triggered_by || 'sistem' }}</dd></div>
        <div><dt class="text-xs text-slate-400">Mulai</dt><dd>{{ run.started_at || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">Selesai</dt><dd>{{ run.finished_at || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">Hash SQL</dt><dd class="font-mono text-xs">{{ run.sql_hash || '—' }}</dd></div>
      </dl>
    </div>

    <div v-if="run.sql_text" class="rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="text-sm font-semibold">SQL yang dieksekusi</h2>
      <pre class="mt-2 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ run.sql_text }}</pre>
    </div>
  </PageHeader>
</template>
