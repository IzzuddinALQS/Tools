<script setup>
import { computed } from 'vue'

const props = defineProps({
  columns: { type: Array, default: () => [] },
  rows: { type: Array, default: () => [] },
  emptyText: { type: String, default: 'Tidak ada data.' },
})

const names = computed(() => props.columns.map((column) => column.name))
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    <div v-if="rows.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">
      {{ emptyText }}
    </div>

    <div v-else class="max-h-96 overflow-auto">
      <table class="w-full border-collapse text-left text-sm">
        <thead class="sticky top-0 bg-slate-50">
          <tr>
            <th
              v-for="name in names"
              :key="name"
              class="whitespace-nowrap border-b border-slate-200 px-3 py-2 font-medium text-slate-700"
            >
              {{ name }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="index" class="border-b border-slate-100 last:border-0">
            <td
              v-for="name in names"
              :key="name"
              class="max-w-xs truncate px-3 py-1.5 align-top text-slate-700"
              :title="row[name] ?? 'NULL'"
              :class="row[name] === 'NULL' ? 'italic text-slate-400' : ''"
            >
              {{ row[name] }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
