<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import WarningList from '../Components/WarningList.vue'
import EmptyState from '../Components/EmptyState.vue'
import Icon from '../Components/Icon.vue'
import Term from '../Components/Term.vue'
import { SYNC_MODE, describeCron, formatNumber, timeAgo } from '../lib/terms.js'

const props = defineProps({
  datasets: { type: Array, default: () => [] },
})

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.datasets
  return props.datasets.filter((d) =>
    [d.name, d.destination, d.description, d.connection].some((v) => (v ?? '').toLowerCase().includes(q)))
})

const toggling = ref(null)
function toggle(dataset) {
  toggling.value = dataset.id
  router.post(`/datasets/${dataset.id}/toggle`, {}, {
    preserveScroll: true,
    onFinish: () => { toggling.value = null },
  })
}
</script>

<template>
  <Head title="Dataset" />

  <PageHeader title="Dataset">
    <template #subtitle>
      Setiap dataset adalah satu query SELECT ke Oracle yang hasilnya disimpan sebagai satu tabel di
      <Term term="pgA" />.
    </template>

    <template #actions>
      <Link href="/datasets/create" class="btn-primary"><Icon name="plus" /> Dataset baru</Link>
    </template>

    <div v-if="datasets.length === 0" class="surface">
      <EmptyState
        icon="table"
        title="Belum ada dataset"
        text="Buat dataset pertama: tulis query SELECT, lihat contoh hasilnya, lalu simpan. Data bisa diambil manual atau terjadwal."
      >
        <Link href="/datasets/create" class="btn-primary"><Icon name="plus" /> Buat dataset pertama</Link>
      </EmptyState>
    </div>

    <template v-else>
      <div v-if="datasets.length > 4" class="relative max-w-sm">
        <input v-model="search" type="search" class="input pl-10" placeholder="Cari dataset…" aria-label="Cari dataset">
        <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-ink-400">
          <Icon name="search" />
        </span>
      </div>

      <p v-if="filtered.length === 0" class="text-sm text-ink-500">Tidak ada dataset yang cocok dengan "{{ search }}".</p>

      <div class="grid gap-4 md:grid-cols-2">
        <article
          v-for="dataset in filtered"
          :key="dataset.id"
          class="surface flex flex-col p-6 transition hover:border-ink-300"
          :class="dataset.status !== 'active' ? 'bg-ink-50' : ''"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <Link :href="`/datasets/${dataset.id}`" class="text-lg font-semibold text-ink-900 hover:text-brand-700">
                {{ dataset.name }}
              </Link>
              <p class="mono mt-0.5 truncate text-ink-500" :title="dataset.destination">{{ dataset.destination }}</p>
            </div>
            <span :class="dataset.status === 'active' ? 'badge-success' : 'badge-neutral'">
              <span class="h-1.5 w-1.5 rounded-full" :class="dataset.status === 'active' ? 'bg-emerald-500' : 'bg-ink-400'"></span>
              {{ dataset.status === 'active' ? 'Aktif' : 'Dijeda' }}
            </span>
          </div>

          <p v-if="dataset.description" class="mt-3 text-sm text-ink-600">{{ dataset.description }}</p>

          <WarningList v-if="dataset.warnings?.length" class="mt-4" title="Perlu perhatian" :warnings="dataset.warnings" />

          <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
            <div>
              <dt class="text-ink-500">Jadwal</dt>
              <dd class="mt-0.5 font-medium text-ink-800">{{ describeCron(dataset.schedule_cron) }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Cara ambil</dt>
              <dd class="mt-0.5 font-medium text-ink-800">{{ SYNC_MODE[dataset.sync_mode] ?? dataset.sync_mode }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Terakhir berhasil</dt>
              <dd class="mt-0.5 font-medium text-ink-800" :title="dataset.last_success_at">{{ timeAgo(dataset.last_success_at) }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Jumlah baris</dt>
              <dd class="mt-0.5 font-medium tabular-nums text-ink-800">{{ formatNumber(dataset.last_row_count) }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Sumber</dt>
              <dd class="mt-0.5 font-medium text-ink-800">{{ dataset.connection || '—' }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Kirim otomatis ke</dt>
              <dd class="mt-0.5 font-medium text-ink-800">
                {{ (dataset.auto_copy_to || []).length ? `${dataset.auto_copy_to.length} aplikasi` : 'Tidak ada' }}
              </dd>
            </div>
          </dl>

          <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-ink-100 pt-4">
            <Link :href="`/datasets/${dataset.id}`" class="btn-secondary btn-sm"><Icon name="edit" :size="16" /> Buka</Link>
            <button type="button" class="btn-ghost btn-sm" :disabled="toggling === dataset.id" @click="toggle(dataset)">
              <Icon :name="dataset.status === 'active' ? 'pause' : 'play'" :size="16" />
              {{ dataset.status === 'active' ? 'Jeda jadwal' : 'Aktifkan' }}
            </button>
          </div>
        </article>
      </div>
    </template>
  </PageHeader>
</template>
