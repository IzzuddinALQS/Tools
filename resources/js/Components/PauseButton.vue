<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  paused: { type: Boolean, default: false },
  full: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  reason: { type: String, default: '' },
})

const pause = useForm({ reason: props.reason })
const resume = useForm({})

function doPause() {
  if (! window.confirm('Pause semua job?\n\nTidak ada penulisan ke PostgreSQL A atau B sampai di-resume.')) {
    return
  }

  pause.post('/pause')
}

function doResume() {
  resume.delete('/pause')
}
</script>

<template>
  <button
    v-if="!paused"
    type="button"
    class="btn btn-sm w-full justify-center border border-red-200 bg-white text-red-700 hover:bg-red-50"
    :class="full ? '' : 'btn-danger'"
    :disabled="pause.processing"
    @click="doPause"
  >
    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" d="M10 9v6m4-6v6" />
    </svg>
    {{ full ? 'Pause semua job' : 'Pause' }}
  </button>

  <button
    v-else
    type="button"
    class="btn btn-sm w-full justify-center border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
    :disabled="resume.processing"
    @click="doResume"
  >
    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 12l5 5L20 7" />
    </svg>
    {{ full ? 'Resume semua job' : 'Resume' }}
  </button>
</template>
