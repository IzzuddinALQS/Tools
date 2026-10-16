<script setup>
import { Head } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import PauseButton from '../Components/PauseButton.vue'

defineProps({
  pause: { type: Object, default: () => ({}) },
})
</script>

<template>
  <Head title="Pause" />

  <PageHeader title="Pause semua job">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">
        Kill switch. Saat aktif, tidak ada penulisan ke PostgreSQL A maupun B, dan job yang
        sedang berjalan berhenti sebelum menulis.
      </p>
    </template>

    <div
      class="rounded-lg border px-4 py-4"
      :class="pause.paused ? 'border-purple-200 bg-purple-50' : 'border-emerald-200 bg-emerald-50'"
    >
      <p class="text-sm font-medium" :class="pause.paused ? 'text-purple-900' : 'text-emerald-900'">
        {{ pause.paused ? 'Semua job sedang di-pause.' : 'Semua job sedang berjalan normal.' }}
      </p>

      <dl v-if="pause.paused" class="mt-2 text-xs text-purple-800">
        <div><span class="opacity-70">Sejak:</span> {{ pause.at }}</div>
        <div v-if="pause.reason"><span class="opacity-70">Alasan:</span> {{ pause.reason }}</div>
      </dl>

      <div class="mt-4">
        <PauseButton :paused="pause.paused" />
      </div>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
      <p class="font-medium text-slate-800">Yang terjadi saat di-pause</p>
      <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
        <li>Job sinkronisasi dan copy berhenti dengan status <code>paused</code>, bukan <code>failed</code>.</li>
        <li>Tabel staging yang belum selesai dibersihkan; tabel lama di PG A tetap utuh.</li>
        <li>Riwayat tetap mencatat kejadiannya, jadi terlihat di halaman Riwayat.</li>
      </ul>
    </div>
  </PageHeader>
</template>
