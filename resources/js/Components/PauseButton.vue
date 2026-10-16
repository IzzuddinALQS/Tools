<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  paused: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  reason: { type: String, default: '' },
})

const form = useForm({ reason: props.reason })
const resuming = useForm({})

function pause() {
  if (! window.confirm('Pause semua job?\n\nTidak ada penulisan ke PostgreSQL A atau B sampai di-resume.')) {
    return
  }

  form.post('/pause')
}

function resume() {
  resuming.delete('/pause')
}
</script>

<template>
  <div class="flex items-center gap-2">
    <button
      v-if="!paused"
      type="button"
      class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
      :disabled="form.processing"
      @click="pause"
    >
      {{ compact ? 'Pause' : 'Pause semua job' }}
    </button>

    <button
      v-else
      type="button"
      class="rounded-lg border border-emerald-300 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50"
      :disabled="resuming.processing"
      @click="resume"
    >
      {{ compact ? 'Resume' : 'Resume semua job' }}
    </button>
  </div>
</template>
