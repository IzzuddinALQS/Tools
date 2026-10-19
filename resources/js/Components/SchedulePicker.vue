<script setup>
import { computed, ref, watch } from 'vue'
import { WEEKDAYS, buildCron, describeCron, parseCron } from '../lib/terms.js'

/*
 * Pemilih jadwal yang bisa dipakai tanpa paham cron. Nilai v-model tetap
 * ekspresi cron, jadi backend tidak berubah. Pola yang tidak dikenali
 * jatuh ke mode "Khusus" agar jadwal lama tidak hilang.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  allowManual: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

const schedule = ref(normalize(parseCron(props.modelValue)))

function normalize(s) {
  return {
    every: 15, minute: 0, time: '02:00', day: 1, cron: props.modelValue || '0 * * * *',
    ...s,
  }
}

watch(() => props.modelValue, (value) => {
  if (value !== buildCron(schedule.value)) {
    schedule.value = normalize(parseCron(value))
  }
})

watch(schedule, (s) => {
  const cron = buildCron(s)
  if (cron !== props.modelValue) emit('update:modelValue', cron)
}, { deep: true })

const types = computed(() => [
  ...(props.allowManual ? [{ value: 'manual', label: 'Manual saja' }] : []),
  { value: 'minutes', label: 'Setiap beberapa menit' },
  { value: 'hourly', label: 'Setiap jam' },
  { value: 'daily', label: 'Setiap hari' },
  { value: 'weekly', label: 'Setiap minggu' },
  { value: 'custom', label: 'Khusus (cron)' },
])

function setType(type) {
  const current = schedule.value
  if (type === 'minutes' && ![1, 5, 10, 15, 30].includes(current.every)) current.every = 15
  if (type === 'hourly' && ![1, 2, 3, 4, 6, 12].includes(current.every)) current.every = 1
  if (type === 'custom') current.cron = buildCron(current) || '0 * * * *'
  current.type = type
}

const summary = computed(() => describeCron(props.modelValue))
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap gap-1.5">
      <button
        v-for="type in types"
        :key="type.value"
        type="button"
        class="rounded-full border px-3 py-1.5 text-[13px] font-medium transition"
        :class="schedule.type === type.value
          ? 'border-brand-500 bg-brand-50 text-brand-700'
          : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300'"
        @click="setType(type.value)"
      >
        {{ type.label }}
      </button>
    </div>

    <div v-if="schedule.type === 'minutes'" class="flex flex-wrap items-center gap-2 text-sm text-ink-700">
      Setiap
      <select v-model.number="schedule.every" class="input-sm w-auto">
        <option v-for="n in [1, 5, 10, 15, 30]" :key="n" :value="n">{{ n }}</option>
      </select>
      menit
    </div>

    <div v-else-if="schedule.type === 'hourly'" class="flex flex-wrap items-center gap-2 text-sm text-ink-700">
      Setiap
      <select v-model.number="schedule.every" class="input-sm w-auto">
        <option v-for="n in [1, 2, 3, 4, 6, 12]" :key="n" :value="n">{{ n }}</option>
      </select>
      jam, pada menit ke-
      <input v-model.number="schedule.minute" type="number" min="0" max="59" class="input-sm w-20">
    </div>

    <div v-else-if="schedule.type === 'daily'" class="flex flex-wrap items-center gap-2 text-sm text-ink-700">
      Setiap hari pukul
      <input v-model="schedule.time" type="time" class="input-sm w-auto">
    </div>

    <div v-else-if="schedule.type === 'weekly'" class="flex flex-wrap items-center gap-2 text-sm text-ink-700">
      Setiap
      <select v-model.number="schedule.day" class="input-sm w-auto">
        <option v-for="(day, index) in WEEKDAYS" :key="day" :value="index">{{ day }}</option>
      </select>
      pukul
      <input v-model="schedule.time" type="time" class="input-sm w-auto">
    </div>

    <div v-else-if="schedule.type === 'custom'">
      <input v-model="schedule.cron" class="input-sm mono" placeholder="0 * * * *">
      <span class="hint">Format cron 5 bagian: menit jam tanggal bulan hari. Contoh <span class="kbd">0 2 * * *</span> = setiap hari pukul 02.00.</span>
    </div>

    <p class="text-[13px] text-ink-500">
      Hasil: <strong class="font-semibold text-ink-800">{{ summary }}</strong>
    </p>
  </div>
</template>
