<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import WarningList from '../Components/WarningList.vue'

defineProps({
  datasets: { type: Array, default: () => [] },
})
</script>

<template>
  <Head title="Dataset" />

  <PageHeader title="Dataset">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">
        Satu query SELECT, satu tabel tujuan. Tabel tujuan hanya boleh dimiliki satu dataset.
      </p>
    </template>

    <template #actions>
      <Link href="/datasets/create"
            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
        Dataset baru
      </Link>
    </template>

    <div v-if="datasets.length === 0" class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center">
      <p class="text-sm text-slate-500">Belum ada dataset.</p>
      <Link href="/datasets/create" class="mt-3 inline-block text-sm font-medium text-slate-900 underline">
        Buat dataset pertama
      </Link>
    </div>

    <div v-else class="space-y-3">
      <div v-for="dataset in datasets" :key="dataset.id"
           class="rounded-lg border border-slate-200 bg-white p-4">
        <WarningList
          v-if="dataset.warnings?.length"
          class="mb-3"
          title="Mode incremental"
          :warnings="dataset.warnings"
        />

        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link :href="`/datasets/${dataset.id}`" class="font-medium text-slate-900 hover:underline">
              {{ dataset.name }}
            </Link>
            <p class="mt-0.5 font-mono text-xs text-slate-500">{{ dataset.destination }}</p>
            <p v-if="dataset.description" class="mt-1 text-xs text-slate-500">{{ dataset.description }}</p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ dataset.sync_mode }}</span>
            <StatusBadge :status="dataset.status" />
          </div>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-600 sm:grid-cols-5">
          <div>
            <dt class="text-slate-400">Sumber</dt>
            <dd>{{ dataset.connection }} ({{ dataset.driver }})</dd>
          </div>
          <div>
            <dt class="text-slate-400">Jadwal</dt>
            <dd class="font-mono">{{ dataset.schedule_cron || '—' }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Copy otomatis</dt>
            <dd>{{ (dataset.auto_copy_to || []).length }} target</dd>
          </div>
          <div>
            <dt class="text-slate-400">Sukses terakhir</dt>
            <dd>{{ dataset.last_success_at || '—' }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Baris terakhir</dt>
            <dd>{{ dataset.last_row_count ?? '—' }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </PageHeader>
</template>
