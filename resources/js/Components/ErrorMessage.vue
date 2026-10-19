<script setup>
import { computed } from 'vue'
import { friendlyError } from '../lib/terms.js'

/*
 * Pesan error yang dikenali tampil sebagai judul + saran dalam bahasa
 * sehari-hari; pesan teknis aslinya disimpan di bagian yang bisa dibuka.
 * Pesan yang tidak dikenali tampil apa adanya.
 */
const props = defineProps({
  message: { type: String, default: '' },
})

const friendly = computed(() => friendlyError(props.message))
</script>

<template>
  <span v-if="message" class="block">
    <template v-if="friendly">
      <strong class="block font-semibold">{{ friendly.title }}</strong>
      <span class="block">{{ friendly.hint }}</span>
      <details class="mt-1 opacity-80">
        <summary class="cursor-pointer text-xs font-medium">Detail teknis</summary>
        <span class="mt-1 block font-mono text-xs break-words">{{ friendly.raw }}</span>
      </details>
    </template>
    <template v-else>{{ message }}</template>
  </span>
</template>
