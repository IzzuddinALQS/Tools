<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
})

const tone = computed(() => ({
  success: { cls: 'bg-emerald-100 text-emerald-800', label: 'Sukses' },
  failed: { cls: 'bg-red-100 text-red-800', label: 'Gagal' },
  running: { cls: 'bg-blue-100 text-blue-800', label: 'Berjalan' },
  queued: { cls: 'bg-slate-100 text-slate-700', label: 'Antre' },
  skipped: { cls: 'bg-amber-100 text-amber-800', label: 'Dilewati' },
  paused: { cls: 'bg-purple-100 text-purple-800', label: 'Di-pause' },
}[props.status] ?? { cls: 'bg-slate-100 text-slate-700', label: props.status }))
</script>

<template>
  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="tone.cls">
    {{ tone.label }}
  </span>
</template>
