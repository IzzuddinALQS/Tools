<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
})

const tone = computed(() => ({
  success: { cls: 'badge-success', label: 'Sukses' },
  failed: { cls: 'badge-danger', label: 'Gagal' },
  running: { cls: 'badge-info', label: 'Berjalan' },
  queued: { cls: 'badge-neutral', label: 'Antre' },
  skipped: { cls: 'badge-warning', label: 'Dilewati' },
  paused: { cls: 'badge-purple', label: 'Di-pause' },
}[props.status] ?? { cls: 'badge-neutral', label: props.status }))
</script>

<template>
  <span :class="tone.cls">{{ tone.label }}</span>
</template>
