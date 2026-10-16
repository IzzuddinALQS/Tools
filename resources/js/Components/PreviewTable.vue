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
  <div class="table-wrap">
    <div v-if="rows.length === 0" class="px-4 py-10 text-center text-sm text-ink-400">
      {{ emptyText }}
    </div>

    <div v-else class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th v-for="name in names" :key="name" class="whitespace-nowrap">{{ name }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="index">
            <td
              v-for="name in names"
              :key="name"
              class="max-w-[22rem] truncate font-mono text-[12.5px]"
              :class="row[name] === 'NULL' ? 'text-ink-300' : 'text-ink-700'"
              :title="row[name] ?? 'NULL'"
            >
              {{ row[name] }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
