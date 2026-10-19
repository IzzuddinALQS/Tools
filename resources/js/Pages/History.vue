<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import EmptyState from '../Components/EmptyState.vue'
import Term from '../Components/Term.vue'
import Icon from '../Components/Icon.vue'
import { RUN_KIND, RUN_TRIGGER, formatDuration, formatNumber, friendlyError, timeAgo, verificationState } from '../lib/terms.js'

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  runs: { type: Object, required: true },
  datasets: { type: Object, default: () => ({}) },
})

const form = ref({
  dataset_id: props.filters.dataset_id || '',
  status: props.filters.status || '',
  kind: props.filters.kind || '',
  verification: props.filters.verification || '',
})

watch(form, () => {
  router.get('/history', form.value, { preserveState: true, preserveScroll: true, replace: true })
}, { deep: true })

const hasFilter = computed(() => Object.values(form.value).some(Boolean))

function resetFilters() {
  form.value = { dataset_id: '', status: '', kind: '', verification: '' }
}

/* Label pagination bawaan Laravel berbahasa Inggris dan memuat entitas HTML. */
function pageLabel(label) {
  if (label.includes('Previous')) return '‹ Sebelumnya'
  if (label.includes('Next')) return 'Berikutnya ›'
  return label
}
</script>

<template>
  <Head title="Riwayat" />

  <PageHeader title="Riwayat">
    <template #subtitle>
      Catatan setiap kali data diambil, dikirim, atau diselaraskan — termasuk hasil
      <Term term="verify" label="pemeriksaan data" />-nya.
    </template>

    <div class="surface flex flex-wrap items-end gap-4 p-5">
      <label class="block min-w-[10rem] flex-1">
        <span class="label">Dataset</span>
        <select v-model="form.dataset_id" class="input-sm mt-2">
          <option value="">Semua dataset</option>
          <option v-for="(name, id) in datasets" :key="id" :value="id">{{ name }}</option>
        </select>
      </label>
      <label class="block min-w-[10rem] flex-1">
        <span class="label">Hasil</span>
        <select v-model="form.status" class="input-sm mt-2">
          <option value="">Semua hasil</option>
          <option value="success">Berhasil</option>
          <option value="failed">Gagal</option>
          <option value="skipped">Dilewati</option>
          <option value="paused">Dihentikan sementara</option>
        </select>
      </label>
      <label class="block min-w-[10rem] flex-1">
        <span class="label">Proses</span>
        <select v-model="form.kind" class="input-sm mt-2">
          <option value="">Semua proses</option>
          <option value="sync">{{ RUN_KIND.sync }}</option>
          <option value="copy">{{ RUN_KIND.copy }}</option>
          <option value="reconcile">{{ RUN_KIND.reconcile }}</option>
        </select>
      </label>
      <label class="block min-w-[10rem] flex-1">
        <span class="label">Pemeriksaan</span>
        <select v-model="form.verification" class="input-sm mt-2">
          <option value="">Semua</option>
          <option value="ok">Data cocok</option>
          <option value="failed">Data tidak cocok</option>
        </select>
      </label>
      <button v-if="hasFilter" type="button" class="btn-ghost btn-sm" @click="resetFilters">
        <Icon name="close" :size="16" /> Hapus filter
      </button>
    </div>

    <div class="table-wrap">
      <EmptyState
        v-if="runs.data.length === 0"
        icon="history"
        :title="hasFilter ? 'Tidak ada yang cocok dengan filter' : 'Belum ada riwayat'"
        :text="hasFilter ? 'Coba ubah atau hapus filter di atas.' : 'Riwayat muncul setelah data diambil atau dikirim untuk pertama kali.'"
      >
        <button v-if="hasFilter" type="button" class="btn-secondary btn-sm" @click="resetFilters">Hapus filter</button>
      </EmptyState>

      <table v-else class="table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Dataset</th>
            <th>Proses</th>
            <th>Hasil</th>
            <th>Pemeriksaan</th>
            <th class="text-right">Baris</th>
            <th class="text-right">Durasi</th>
            <th><span class="sr-only">Detail</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="run in runs.data" :key="run.id" class="align-top">
            <td class="text-sm whitespace-nowrap" :title="run.started_at">{{ timeAgo(run.started_at) }}</td>
            <td class="font-semibold text-ink-900">{{ run.dataset || '—' }}</td>
            <td class="text-sm">
              {{ RUN_KIND[run.kind] ?? run.kind }}
              <span class="block text-ink-500">
                {{ RUN_TRIGGER[run.trigger] ?? run.trigger }}<template v-if="run.triggered_by"> · {{ run.triggered_by }}</template>
              </span>
            </td>
            <td>
              <StatusBadge :status="run.status" />
              <p v-if="run.error_message" class="mt-1.5 line-clamp-2 max-w-xs text-[13px] leading-snug text-red-700" :title="run.error_message">
                {{ friendlyError(run.error_message)?.title ?? run.error_message }}
              </p>
            </td>
            <td><VerifyBadge :matched="verificationState(run.verification)" :detail="run.verification_detail" compact /></td>
            <td class="text-right tabular-nums">{{ formatNumber(run.row_count) }}</td>
            <td class="text-right text-sm whitespace-nowrap text-ink-600">{{ formatDuration(run.duration_ms) }}</td>
            <td class="text-right">
              <Link :href="`/history/${run.id}`" class="btn-ghost btn-xs" :aria-label="`Detail proses #${run.id}`">
                Detail <Icon name="chevron" :size="14" />
              </Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="runs.links?.length > 3" class="flex flex-wrap justify-center gap-1.5" aria-label="Halaman">
      <component
        :is="link.url ? Link : 'span'"
        v-for="(link, index) in runs.links"
        :key="index"
        :href="link.url"
        preserve-scroll
        class="min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm"
        :class="link.active
          ? 'border-brand-600 bg-brand-600 font-semibold text-white'
          : link.url ? 'border-ink-200 bg-white text-ink-700 hover:bg-ink-50' : 'border-ink-100 text-ink-400'"
      >
        {{ pageLabel(link.label) }}
      </component>
    </nav>
  </PageHeader>
</template>
