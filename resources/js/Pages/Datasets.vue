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
      Satu query SELECT, satu tabel tujuan. Tabel tujuan hanya boleh dimiliki satu dataset.
    </template>

    <template #actions>
      <Link href="/datasets/create" class="btn-primary">Dataset baru</Link>
    </template>

    <div v-if="datasets.length === 0" class="surface px-5 py-12 text-center">
      <p class="text-sm text-ink-500">Belum ada dataset.</p>
      <Link href="/datasets/create" class="btn-secondary mt-4">Buat dataset pertama</Link>
    </div>

    <div v-else class="space-y-3">
      <div v-for="dataset in datasets" :key="dataset.id" class="surface p-5">
        <WarningList
          v-if="dataset.warnings?.length"
          class="mb-4"
          title="Mode incremental"
          :warnings="dataset.warnings"
        />

        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <Link :href="`/datasets/${dataset.id}`" class="font-medium text-ink-900 hover:text-brand-700 hover:underline">
              {{ dataset.name }}
            </Link>
            <p class="mono mt-0.5 text-ink-500">{{ dataset.destination }}</p>
            <p v-if="dataset.description" class="mt-1.5 text-xs text-ink-500">{{ dataset.description }}</p>
          </div>

          <div class="flex shrink-0 items-center gap-2">
            <span class="badge-neutral">{{ dataset.sync_mode }}</span>
            <StatusBadge :status="dataset.status" />
          </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-ink-100 pt-4 text-xs sm:grid-cols-5">
          <div>
            <dt class="text-ink-400">Sumber</dt>
            <dd class="mt-0.5 font-medium text-ink-700">{{ dataset.connection }}
              <span class="font-normal text-ink-400">({{ dataset.driver }})</span>
            </dd>
          </div>
          <div>
            <dt class="text-ink-400">Jadwal</dt>
            <dd class="mono mt-0.5 text-ink-700">{{ dataset.schedule_cron || '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-400">Copy otomatis</dt>
            <dd class="mt-0.5 text-ink-700">{{ (dataset.auto_copy_to || []).length }} target</dd>
          </div>
          <div>
            <dt class="text-ink-400">Sukses terakhir</dt>
            <dd class="mt-0.5 text-ink-700">{{ dataset.last_success_at || '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-400">Baris terakhir</dt>
            <dd class="mt-0.5 tabular-nums text-ink-700">{{ dataset.last_row_count ?? '—' }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </PageHeader>
</template>
