<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, default: 'Verifikasi' },
  matched: { type: [Boolean, null], default: null },
  detail: { type: Object, default: null },
  compact: { type: Boolean, default: false },
})

const state = computed(() => {
  if (props.matched === null || props.matched === undefined) {
    return { tone: 'slate', mark: '–', text: 'tidak diperiksa' }
  }

  return props.matched
    ? { tone: 'emerald', mark: '✅', text: 'identik' }
    : { tone: 'red', mark: '❌', text: 'tidak identik' }
})

const toneClass = computed(() => ({
  emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  red: 'border-red-200 bg-red-50 text-red-800',
  slate: 'border-slate-200 bg-slate-50 text-slate-600',
}[state.value.tone]))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="toneClass"
    :title="detail ? JSON.stringify(detail, null, 2) : label"
  >
    <span>{{ state.mark }}</span>
    <span v-if="!compact">{{ label }}: {{ state.text }}</span>
    <span v-if="detail && !compact" class="opacity-70">
      · {{ detail.source?.row_count ?? detail.target?.row_count ?? 0 }} baris
    </span>
  </span>
</template>
