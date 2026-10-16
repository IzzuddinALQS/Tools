<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import WarningList from '../Components/WarningList.vue'

const props = defineProps({
  stats: { type: Object, required: true },
  recentRuns: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
})

const cards = computed(() => [
  { label: 'Koneksi sumber', value: props.stats.connections },
  { label: 'Target PostgreSQL', value: props.stats.targets },
  { label: 'Dataset', value: props.stats.datasets },
  { label: 'Dataset aktif', value: props.stats.datasets_active },
])
</script>

<template>
  <PageHeader title="Dashboard">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">
        Oracle hanya dibaca, tidak pernah ditulis. PG A menyimpan hasil query, PG B adalah salinan untuk aplikasi.
      </p>
    </template>

    <template #actions>
      <Link
        href="/datasets/create"
        class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
      >
        Dataset baru
      </Link>
    </template>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="card in cards"
        :key="card.label"
        class="rounded-lg border border-slate-200 bg-white px-4 py-3"
      >
        <p class="text-xs uppercase tracking-wide text-slate-500">{{ card.label }}</p>
        <p class="mt-1 text-2xl font-semibold">{{ card.value }}</p>
      </div>
    </div>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Dataset</h2>

      <div v-if="datasets.length === 0" class="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
        Belum ada dataset. Mulai dari halaman Dataset, tulis query Oracle, lalu Preview.
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="dataset in datasets"
          :key="dataset.id"
          class="rounded-lg border border-slate-200 bg-white p-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <Link :href="`/datasets/${dataset.id}`" class="font-medium text-slate-900 hover:underline">
                {{ dataset.name }}
              </Link>
              <p class="mt-0.5 font-mono text-xs text-slate-500">{{ dataset.destination }}</p>
            </div>

            <div class="flex items-center gap-2">
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                {{ dataset.sync_mode }}
              </span>
              <StatusBadge :status="dataset.status" />
            </div>
          </div>

          <WarningList
            v-if="dataset.warnings?.length"
            class="mt-3"
            title="Mode incremental"
            :warnings="dataset.warnings"
          />

          <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-600 sm:grid-cols-4">
            <div>
              <dt class="text-slate-400">Sumber</dt>
              <dd>{{ dataset.connection }} ({{ dataset.driver }})</dd>
            </div>
            <div>
              <dt class="text-slate-400">Jadwal</dt>
              <dd class="font-mono">{{ dataset.schedule_cron || '—' }}</dd>
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
    </section>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Eksekusi terakhir</h2>

      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Waktu</th>
              <th class="px-3 py-2">Dataset</th>
              <th class="px-3 py-2">Jenis</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Verifikasi</th>
              <th class="px-3 py-2 text-right">Baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="recentRuns.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-slate-500">Belum ada eksekusi.</td>
            </tr>
            <tr v-for="run in recentRuns" :key="run.id" class="border-t border-slate-100">
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.finished_at || '—' }}</td>
              <td class="px-3 py-2">
                <Link :href="`/history/${run.id}`" class="hover:underline">{{ run.dataset }}</Link>
              </td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.kind }}</td>
              <td class="px-3 py-2"><StatusBadge :status="run.status" /></td>
              <td class="px-3 py-2"><VerifyBadge :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null" compact /></td>
              <td class="px-3 py-2 text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
