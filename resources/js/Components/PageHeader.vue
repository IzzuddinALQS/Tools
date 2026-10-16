<script setup>
defineProps({
  title: { type: String, default: '' },
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
        <slot name="subtitle" />
      </div>
      <slot name="actions" />
    </div>

    <slot name="alerts" />

    <slot />
  </div>
</template>
