<script setup>
import { computed } from 'vue'

const props = defineProps({
  matched: { type: [Boolean, null], default: null },
  detail: { type: Object, default: null },
  compact: { type: Boolean, default: false },
})

const state = computed(() => {
  if (props.matched === null || props.matched === undefined) {
    return { cls: 'badge-neutral', mark: '—', text: 'tidak diperiksa' }
  }

  return props.matched
    ? { cls: 'badge-success', mark: '✓', text: 'Hash identik' }
    : { cls: 'badge-danger', mark: '✗', text: 'Hash berbeda' }
})

const rows = computed(() => props.detail?.source?.row_count ?? props.detail?.target?.row_count ?? null)
const title = computed(() => props.detail ? JSON.stringify(props.detail, null, 2) : 'Tidak ada detail verifikasi.')
</script>

<template>
  <span :class="state.cls" :title="title">
    <span class="font-semibold">{{ state.mark }}</span>
    <span v-if="!compact">{{ state.text }}</span>
    <span v-if="rows !== null && !compact" class="opacity-70">· {{ rows }} baris</span>
  </span>
</template>
