<script setup>
import { onBeforeUnmount, watch } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  size: { type: String, default: 'md' },
})

const emit = defineEmits(['close'])

function onKey(event) {
  if (event.key === 'Escape') emit('close')
}

watch(() => props.open, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  if (open) window.addEventListener('keydown', onKey)
  else window.removeEventListener('keydown', onKey)
}, { immediate: true })

onBeforeUnmount(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKey)
})

const widths = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' }
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 p-0 backdrop-blur-[2px] sm:items-center sm:p-6"
        @mousedown.self="emit('close')"
      >
        <div
          role="dialog"
          aria-modal="true"
          :aria-label="title"
          class="flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-surface shadow-2xl sm:rounded-2xl"
          :class="widths[size]"
        >
          <header class="flex items-start justify-between gap-4 border-b border-ink-100 px-6 pt-5 pb-4">
            <div class="min-w-0">
              <h2 class="text-lg font-semibold text-ink-900">{{ title }}</h2>
              <p v-if="description" class="mt-1 text-sm text-ink-500">{{ description }}</p>
            </div>
            <button type="button" class="btn-ghost btn-xs -mr-2" aria-label="Tutup" @click="emit('close')">
              <Icon name="close" />
            </button>
          </header>

          <div class="overflow-y-auto px-6 py-5">
            <slot />
          </div>

          <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-ink-100 bg-ink-50/60 px-6 py-4 sm:rounded-b-2xl">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
