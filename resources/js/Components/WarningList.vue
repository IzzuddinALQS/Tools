<script setup>
import { computed } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  warnings: { type: Array, default: () => [] },
  title: { type: String, default: 'Perhatian' },
  tone: { type: String, default: 'amber' },
})

const styles = computed(() => ({
  red: { box: 'border-red-200 bg-red-50', icon: 'text-red-600', title: 'text-red-900', body: 'text-red-800', name: 'alert' },
  info: { box: 'border-brand-200 bg-brand-50', icon: 'text-brand-600', title: 'text-brand-900', body: 'text-brand-800', name: 'info' },
  amber: { box: 'border-amber-200 bg-amber-50', icon: 'text-amber-600', title: 'text-amber-900', body: 'text-amber-900', name: 'alert' },
}[props.tone] ?? {}))
</script>

<template>
  <div v-if="warnings.length || $slots.default" class="flex gap-3 rounded-xl border px-4 py-3.5" :class="styles.box">
    <span class="mt-0.5" :class="styles.icon"><Icon :name="styles.name" :size="18" /></span>
    <div class="min-w-0 text-sm leading-relaxed">
      <p class="font-semibold" :class="styles.title">{{ title }}</p>
      <ul v-if="warnings.length" class="mt-1 space-y-0.5" :class="[styles.body, warnings.length > 1 ? 'list-disc pl-4' : '']">
        <li v-for="(warning, index) in warnings" :key="index">{{ warning }}</li>
      </ul>
      <div v-if="$slots.default" class="mt-1" :class="styles.body"><slot /></div>
    </div>
  </div>
</template>
