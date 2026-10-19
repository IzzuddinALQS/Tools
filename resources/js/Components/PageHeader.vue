<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})
</script>

<template>
  <div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0 max-w-2xl">
        <slot name="back" />
        <h1 class="page-title">{{ title }}</h1>
        <p v-if="subtitle || $slots.subtitle" class="mt-2 text-[15px] leading-relaxed text-ink-500">
          <slot name="subtitle">{{ subtitle }}</slot>
        </p>
      </div>
      <div v-if="$slots.actions" class="flex shrink-0 flex-wrap gap-2">
        <slot name="actions" />
      </div>
    </div>

    <slot name="alerts" />
    <slot />
  </div>
</template>
