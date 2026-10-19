<script setup>
import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Icon from './Icon.vue'
import ErrorMessage from './ErrorMessage.vue'

/*
 * Pesan flash dari server tampil sebagai toast di pojok layar, jadi tetap
 * terlihat walau pengguna sedang di bawah halaman (preserveScroll).
 * Pesan sukses hilang sendiri; pesan error menunggu ditutup.
 */
const page = usePage()
const toasts = ref([])
let seq = 0

function push(type, text) {
  const id = ++seq
  toasts.value.push({ id, type, text })
  if (type === 'success') {
    window.setTimeout(() => dismiss(id), 6000)
  }
}

function dismiss(id) {
  toasts.value = toasts.value.filter((toast) => toast.id !== id)
}

watch(() => page.props.flash, (flash) => {
  if (flash?.success) push('success', flash.success)
  if (flash?.error) push('error', flash.error)
}, { immediate: true })
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border bg-white p-4 shadow-lg"
        :class="toast.type === 'success' ? 'border-emerald-200' : 'border-red-200'"
        role="status"
      >
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
          :class="toast.type === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
        >
          <Icon :name="toast.type === 'success' ? 'check' : 'alert'" :size="16" />
        </span>
        <div class="min-w-0 flex-1 pt-0.5 text-sm leading-relaxed text-ink-800"><ErrorMessage :message="toast.text" /></div>
        <button type="button" class="text-ink-400 hover:text-ink-700" aria-label="Tutup" @click="dismiss(toast.id)">
          <Icon name="close" :size="16" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
