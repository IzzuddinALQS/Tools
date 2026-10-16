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
      Kill switch. Saat aktif, tidak ada penulisan ke PostgreSQL A maupun B.
    </template>

    <div class="surface p-6">
      <div class="flex flex-wrap items-center justify-between gap-5">
        <div class="flex items-center gap-4">
          <span
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-semibold"
            :class="pause.paused ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700'"
          >
            {{ pause.paused ? '‖' : '▶' }}
          </span>
          <div>
            <p class="text-sm font-semibold text-ink-900">
              {{ pause.paused ? 'Semua job sedang di-pause.' : 'Semua job berjalan normal.' }}
            </p>
            <dl v-if="pause.paused" class="mt-1 space-y-0.5 text-xs text-ink-500">
              <div v-if="pause.at">
                <dt class="inline text-ink-400">Sejak: </dt>
                <dd class="inline">{{ pause.at }}</dd>
              </div>
              <div v-if="pause.reason">
                <dt class="inline text-ink-400">Alasan: </dt>
                <dd class="inline">{{ pause.reason }}</dd>
              </div>
            </dl>
            <p v-else class="mt-1 text-xs text-ink-500">
              Sinkronisasi dan copy berjalan sesuai jadwal masing-masing dataset.
            </p>
          </div>
        </div>

        <PauseButton :paused="pause.paused" />
      </div>
    </div>

    <div class="surface p-6">
      <h2 class="text-sm font-semibold text-ink-900">Yang terjadi saat di-pause</h2>
      <ul class="mt-3 space-y-2 text-[13px] leading-relaxed text-ink-600">
        <li class="flex gap-2.5">
          <span class="text-ink-300">•</span>
          Job sinkronisasi dan copy berhenti dengan status <code class="rounded bg-ink-100 px-1 font-mono text-[11px]">paused</code>,
          bukan <code class="rounded bg-ink-100 px-1 font-mono text-[11px]">failed</code>.
        </li>
        <li class="flex gap-2.5">
          <span class="text-ink-300">•</span>
          Tabel staging yang belum selesai dibersihkan; tabel lama di PG A tetap utuh.
        </li>
        <li class="flex gap-2.5">
          <span class="text-ink-300">•</span>
          Riwayat tetap mencatat kejadiannya, jadi terlihat di halaman Riwayat.
        </li>
      </ul>
    </div>
  </PageHeader>
</template>
