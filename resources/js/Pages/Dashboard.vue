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
  { label: 'Koneksi sumber', value: props.stats.connections, hint: 'mock / oracle' },
  { label: 'Target PostgreSQL', value: props.stats.targets, hint: 'PG A + PG B' },
  { label: 'Dataset', value: props.stats.datasets, hint: 'total terdaftar' },
  { label: 'Dataset aktif', value: props.stats.datasets_active, hint: 'dijadwalkan' },
])
</script>

<template>
  <PageHeader title="Dashboard">
    <template #subtitle>
      Oracle hanya dibaca, tidak pernah ditulis. PG A menyimpan hasil query,
      PG B adalah salinan untuk aplikasi.
    </template>

    <template #actions>
      <Link href="/datasets/create" class="btn-primary">Dataset baru</Link>
    </template>

    <!-- Statistik -->
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="card in cards" :key="card.label" class="surface px-5 py-4">
        <p class="eyebrow">{{ card.label }}</p>
        <p class="mt-1.5 text-3xl font-semibold tabular-nums text-ink-900">{{ card.value }}</p>
        <p class="mt-0.5 text-xs text-ink-400">{{ card.hint }}</p>
      </div>
    </div>

    <!-- Dataset -->
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="eyebrow">Dataset</h2>
        <Link href="/datasets" class="text-xs font-medium text-brand-700 hover:underline">Lihat semua</Link>
      </div>

      <div v-if="datasets.length === 0" class="surface px-5 py-10 text-center">
        <p class="text-sm text-ink-500">Belum ada dataset.</p>
        <p class="mt-1 text-xs text-ink-400">
          Mulai dari halaman Dataset: tulis query Oracle, tekan Preview, lalu simpan.
        </p>
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
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <span class="badge-neutral">{{ dataset.sync_mode }}</span>
              <StatusBadge :status="dataset.status" />
            </div>
          </div>

          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-ink-100 pt-4 text-xs sm:grid-cols-4">
            <div>
              <dt class="text-ink-400">Sumber</dt>
              <dd class="mt-0.5 font-medium text-ink-700">{{ dataset.connection }}
                <span class="text-ink-400">({{ dataset.driver }})</span>
              </dd>
            </div>
            <div>
              <dt class="text-ink-400">Jadwal</dt>
              <dd class="mono mt-0.5 text-ink-700">{{ dataset.schedule_cron || '—' }}</dd>
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
    </section>

    <!-- Eksekusi terakhir -->
    <section class="space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="eyebrow">Eksekusi terakhir</h2>
        <Link href="/history" class="text-xs font-medium text-brand-700 hover:underline">Lihat riwayat lengkap</Link>
      </div>

      <div class="table-wrap">
        <div v-if="recentRuns.length === 0" class="px-5 py-10 text-center text-sm text-ink-400">
          Belum ada eksekusi.
        </div>

        <table v-else class="table">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Dataset</th>
              <th>Jenis</th>
              <th>Status</th>
              <th>Verifikasi</th>
              <th class="text-right">Baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in recentRuns" :key="run.id">
              <td class="whitespace-nowrap text-xs text-ink-500">{{ run.finished_at || '—' }}</td>
              <td class="font-medium">
                <Link :href="`/history/${run.id}`" class="hover:text-brand-700 hover:underline">{{ run.dataset }}</Link>
              </td>
              <td class="text-xs text-ink-500">{{ run.kind }}</td>
              <td><StatusBadge :status="run.status" /></td>
              <td>
                <VerifyBadge
                  :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null"
                  compact
                />
              </td>
              <td class="text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
