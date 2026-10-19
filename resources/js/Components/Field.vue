<script setup>
import Term from './Term.vue'

/*
 * Pembungkus satu isian form: label, (opsional) penjelasan istilah,
 * teks bantuan, dan pesan error yang menempel di field-nya sendiri.
 */
defineProps({
  label: { type: String, default: '' },
  term: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: [String, Array], default: '' },
  optional: { type: Boolean, default: false },
  tag: { type: String, default: 'label' },
})
</script>

<template>
  <component :is="tag" class="block">
    <span v-if="label || term" class="label flex items-center gap-1.5">
      <Term v-if="term" :term="term" :label="label" />
      <template v-else>{{ label }}</template>
      <span v-if="optional" class="text-xs font-normal text-ink-500">(opsional)</span>
    </span>
    <div class="mt-2.5">
      <slot />
    </div>
    <span v-if="error" class="error-text">{{ Array.isArray(error) ? error[0] : error }}</span>
    <span v-else-if="hint || $slots.hint" class="hint"><slot name="hint">{{ hint }}</slot></span>
  </component>
</template>
