<script setup>
import { computed, ref } from 'vue'
import { TERMS } from '../lib/terms.js'
import Icon from './Icon.vue'

/*
 * Istilah dengan penjelasan singkat. Klik / fokus ikon "?" untuk membaca
 * penjelasan — berfungsi juga di layar sentuh, tidak hanya hover.
 */
const props = defineProps({
  term: { type: String, required: true },
  label: { type: String, default: '' },
  iconOnly: { type: Boolean, default: false },
})

const entry = computed(() => TERMS[props.term] ?? { label: props.term, help: '' })
const open = ref(false)
</script>

<template>
  <span class="relative inline whitespace-nowrap">
    <span v-if="!iconOnly">{{ label || entry.label }}</span>
    <button
      v-if="entry.help"
      type="button"
      class="ml-1 inline-flex align-[-2px] text-ink-400 transition hover:text-brand-600 focus-visible:text-brand-600"
      :aria-label="`Apa itu ${entry.label}?`"
      :aria-expanded="open"
      @click.stop.prevent="open = !open"
      @blur="open = false"
    >
      <Icon name="help" :size="15" />
    </button>
    <span
      v-if="open"
      role="tooltip"
      class="absolute top-full left-0 z-40 mt-2 w-72 rounded-xl bg-slate-900 p-3.5 text-left text-[13px] leading-relaxed font-normal tracking-normal text-white normal-case shadow-xl"
    >
      <strong class="block font-semibold">{{ entry.label }}<span v-if="entry.tech" class="font-normal text-slate-300"> · {{ entry.tech }}</span></strong>
      <span class="mt-1 block text-slate-200">{{ entry.help }}</span>
    </span>
  </span>
</template>
