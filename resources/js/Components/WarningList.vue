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
    class="flex gap-3 rounded-lg border px-4 py-3"
    :class="tone === 'red'
      ? 'border-red-200 bg-red-50'
      : tone === 'info'
        ? 'border-brand-200 bg-brand-50'
        : 'border-amber-200 bg-amber-50'"
  >
    <span
      class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
      :class="tone === 'red' ? 'bg-red-200 text-red-800' : tone === 'info' ? 'bg-brand-200 text-brand-800' : 'bg-amber-200 text-amber-900'"
    >
      !
    </span>
    <div class="min-w-0 text-[13px] leading-relaxed">
      <p
        class="font-semibold"
        :class="tone === 'red' ? 'text-red-800' : tone === 'info' ? 'text-brand-800' : 'text-amber-900'"
      >
        {{ title }}
      </p>
      <ul class="mt-1 list-disc space-y-0.5 pl-4"
          :class="tone === 'red' ? 'text-red-700' : tone === 'info' ? 'text-brand-700' : 'text-amber-800'">
        <li v-for="(warning, index) in warnings" :key="index">{{ warning }}</li>
      </ul>
    </div>
  </div>
</template>
