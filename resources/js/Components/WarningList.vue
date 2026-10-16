<script setup>
defineProps({
  warnings: { type: Array, default: () => [] },
  title: { type: String, default: 'Perhatian' },
  tone: { type: String, default: 'amber' },
})
</script>

<template>
  <div
    v-if="warnings.length"
    class="rounded-lg border px-4 py-3 text-sm"
    :class="tone === 'red'
      ? 'border-red-200 bg-red-50 text-red-800'
      : 'border-amber-200 bg-amber-50 text-amber-900'"
  >
    <p class="font-medium">{{ title }}</p>
    <ul class="mt-1 list-disc space-y-0.5 pl-5">
      <li v-for="(warning, index) in warnings" :key="index">{{ warning }}</li>
    </ul>
  </div>
</template>
