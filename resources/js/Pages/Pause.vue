<script setup>
import { Head } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import PauseButton from '../Components/PauseButton.vue'
import Icon from '../Components/Icon.vue'
import { formatDate, timeAgo } from '../lib/terms.js'

defineProps({
  pause: { type: Object, default: () => ({}) },
})
</script>

<template>
  <Head title="Tombol darurat" />

  <PageHeader title="Tombol darurat">
    <template #subtitle>
      Hentikan semua pengambilan dan pengiriman data dengan satu tombol, misalnya saat Oracle sedang
      maintenance atau ada yang terlihat tidak beres.
    </template>

    <div
      class="surface p-6 sm:p-8"
      :class="pause.paused ? 'border-purple-300 bg-purple-50/50' : ''"
    >
      <div class="flex flex-wrap items-center justify-between gap-6">
        <div class="flex items-center gap-5">
          <span
            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl"
            :class="pause.paused ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700'"
          >
            <Icon :name="pause.paused ? 'pause' : 'check-circle'" :size="28" />
          </span>
          <div>
            <p class="text-xl font-bold text-ink-900">
              {{ pause.paused ? 'Semua proses sedang dihentikan' : 'Semua proses berjalan normal' }}
            </p>
            <dl v-if="pause.paused" class="mt-1 space-y-0.5 text-sm text-ink-600">
              <div v-if="pause.at">
                <dt class="inline">Sejak </dt>
                <dd class="inline font-medium text-ink-800" :title="formatDate(pause.at)">{{ timeAgo(pause.at) }}</dd>
              </div>
              <div v-if="pause.reason">
                <dt class="inline">Alasan: </dt>
                <dd class="inline font-medium text-ink-800">{{ pause.reason }}</dd>
              </div>
            </dl>
            <p v-else class="mt-1 text-sm text-ink-600">
              Setiap dataset diambil dan dikirim sesuai jadwalnya masing-masing.
            </p>
          </div>
        </div>

        <PauseButton :paused="pause.paused" />
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
      <div class="surface p-6">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="shield" :size="20" /></span>
        <p class="mt-4 font-semibold text-ink-900">Aman untuk data lama</p>
        <p class="mt-1 text-sm text-ink-600">
          Proses yang sedang berjalan dihentikan sebelum menulis. Tabel yang sudah ada tidak berubah sedikit pun.
        </p>
      </div>
      <div class="surface p-6">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="history" :size="20" /></span>
        <p class="mt-4 font-semibold text-ink-900">Tetap tercatat</p>
        <p class="mt-1 text-sm text-ink-600">
          Proses yang batal dicatat sebagai <strong>Dihentikan sementara</strong> di halaman Riwayat, bukan sebagai gagal.
        </p>
      </div>
      <div class="surface p-6">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="play" :size="20" /></span>
        <p class="mt-4 font-semibold text-ink-900">Lanjutkan kapan saja</p>
        <p class="mt-1 text-sm text-ink-600">
          Setelah dilanjutkan, jadwal berikutnya berjalan seperti biasa. Tidak ada yang perlu diatur ulang.
        </p>
      </div>
    </div>
  </PageHeader>
</template>
