<script setup>
import Icon from './Icon.vue'

defineProps({
  icon: { type: String, default: 'table' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-col items-center px-6 py-14 text-center">
    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
      <Icon :name="icon" :size="24" />
    </span>
    <p class="mt-4 font-semibold text-ink-900">{{ title }}</p>
    <p v-if="text" class="mt-1 max-w-md text-sm text-ink-500">{{ text }}</p>
    <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-2">
      <slot />
    </div>
  </div>
</template>
