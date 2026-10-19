<script setup>
import { useForm } from '@inertiajs/vue3'
import { confirm } from '../lib/confirm.js'
import Icon from './Icon.vue'

const props = defineProps({
  paused: { type: Boolean, default: false },
  full: { type: Boolean, default: false },
})

const pause = useForm({ reason: '' })
const resume = useForm({})

async function doPause() {
  const result = await confirm({
    title: 'Hentikan semua proses?',
    message: 'Tombol darurat ini menghentikan semua pengambilan dan pengiriman data sampai Anda melanjutkannya lagi.',
    details: [
      'Tidak ada data yang ditulis ke Database Penampung maupun Database Aplikasi.',
      'Proses yang sedang berjalan berhenti dengan aman; data lama tetap utuh.',
    ],
    input: { label: 'Alasan (opsional)', placeholder: 'mis. Oracle sedang maintenance' },
    confirmLabel: 'Hentikan semua',
    tone: 'danger',
  })

  if (!result.ok) return

  pause.reason = result.value
  pause.post('/pause', { preserveScroll: true })
}

async function doResume() {
  const ok = await confirm({
    title: 'Lanjutkan semua proses?',
    message: 'Jadwal pengambilan dan pengiriman data akan berjalan normal lagi.',
    confirmLabel: 'Lanjutkan',
  })

  if (ok) resume.delete('/pause', { preserveScroll: true })
}
</script>

<template>
  <button
    v-if="!paused"
    type="button"
    class="btn-danger"
    :class="full ? 'w-full' : ''"
    :disabled="pause.processing"
    @click="doPause"
  >
    <Icon name="pause" />
    {{ full ? 'Hentikan semua' : 'Hentikan' }}
  </button>

  <button
    v-else
    type="button"
    class="btn border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100"
    :class="full ? 'w-full' : ''"
    :disabled="resume.processing"
    @click="doResume"
  >
    <Icon name="play" />
    {{ full ? 'Lanjutkan semua' : 'Lanjutkan' }}
  </button>
</template>
