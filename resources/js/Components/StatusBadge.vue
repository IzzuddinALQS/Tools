<script setup>
import { computed } from 'vue'
import { RUN_STATUS } from '../lib/terms.js'

const props = defineProps({
  status: { type: String, required: true },
})

const tone = computed(() => RUN_STATUS[props.status] ?? { cls: 'badge-neutral', label: props.status, dot: 'bg-ink-400' })
</script>

<template>
  <span :class="tone.cls">
    <span class="h-1.5 w-1.5 rounded-full" :class="[tone.dot, status === 'running' ? 'animate-pulse' : '']"></span>
    {{ tone.label }}
  </span>
</template>
