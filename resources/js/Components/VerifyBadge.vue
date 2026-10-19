<script setup>
import { computed } from 'vue'
import { formatNumber } from '../lib/terms.js'

const props = defineProps({
  matched: { type: [Boolean, null], default: null },
  detail: { type: Object, default: null },
  compact: { type: Boolean, default: false },
})

const state = computed(() => {
  if (props.matched === null || props.matched === undefined) {
    return { cls: 'badge-neutral', mark: '–', text: 'Belum diperiksa', short: 'Belum diperiksa' }
  }

  return props.matched
    ? { cls: 'badge-success', mark: '✓', text: 'Data cocok', short: 'Cocok' }
    : { cls: 'badge-danger', mark: '✗', text: 'Data tidak cocok', short: 'Tidak cocok' }
})

const rows = computed(() => props.detail?.source?.row_count ?? props.detail?.target?.row_count ?? null)
</script>

<template>
  <span :class="state.cls">
    <span class="font-bold" aria-hidden="true">{{ state.mark }}</span>
    {{ compact ? state.short : state.text }}
    <span v-if="rows !== null && !compact" class="opacity-75">· {{ formatNumber(rows) }} baris</span>
  </span>
</template>
