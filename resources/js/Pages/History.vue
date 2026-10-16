<script setup>
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'

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
  router.get('/history', form.value, { preserveState: true, replace: true })
}, { deep: true })
</script>

<template>
  <Head title="Riwayat" />

  <PageHeader title="Riwayat eksekusi">
    <template #subtitle>
      Waktu, durasi, jumlah baris, status, pemicu, dan hasil verifikasi hash setiap eksekusi.
    </template>

    <div class="surface grid gap-3 p-4 sm:grid-cols-4">
      <label class="block">
        <span class="eyebrow">Dataset</span>
        <select v-model="form.dataset_id" class="input-sm mt-1.5">
          <option value="">Semua</option>
          <option v-for="(name, id) in datasets" :key="id" :value="id">{{ name }}</option>
        </select>
      </label>
      <label class="block">
        <span class="eyebrow">Status</span>
        <select v-model="form.status" class="input-sm mt-1.5">
          <option value="">Semua</option>
          <option value="success">Sukses</option>
          <option value="failed">Gagal</option>
          <option value="skipped">Dilewati</option>
          <option value="paused">Di-pause</option>
        </select>
      </label>
      <label class="block">
        <span class="eyebrow">Jenis</span>
        <select v-model="form.kind" class="input-sm mt-1.5">
          <option value="">Semua</option>
          <option value="sync">Sync</option>
          <option value="copy">Copy</option>
          <option value="reconcile">Rekonsiliasi</option>
        </select>
      </label>
      <label class="block">
        <span class="eyebrow">Verifikasi</span>
        <select v-model="form.verification" class="input-sm mt-1.5">
          <option value="">Semua</option>
          <option value="ok">✓ Hash identik</option>
          <option value="failed">✗ Hash berbeda</option>
        </select>
      </label>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Dataset</th>
            <th>Jenis / pemicu</th>
            <th>Status</th>
            <th>Verifikasi</th>
            <th class="text-right">Baris</th>
            <th class="text-right">Durasi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="runs.data.length === 0">
            <td colspan="7" class="py-10 text-center text-ink-400">Belum ada eksekusi.</td>
          </tr>
          <tr v-for="run in runs.data" :key="run.id" class="align-top">
            <td class="whitespace-nowrap text-xs text-ink-500">{{ run.started_at }}</td>
            <td class="font-medium">
              <Link :href="`/history/${run.id}`" class="hover:text-brand-700 hover:underline">{{ run.dataset || '—' }}</Link>
            </td>
            <td class="text-xs text-ink-500">
              {{ run.kind }} · {{ run.trigger }}
              <span v-if="run.triggered_by" class="block text-ink-400">{{ run.triggered_by }}</span>
            </td>
            <td><StatusBadge :status="run.status" /></td>
            <td>
              <VerifyBadge
                :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null"
                :detail="run.verification_detail"
              />
              <p v-if="run.error_message" class="mt-1 max-w-sm text-xs leading-relaxed text-red-600">
                {{ run.error_message }}
              </p>
            </td>
            <td class="text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            <td class="whitespace-nowrap text-right text-xs text-ink-500">{{ run.duration_ms ?? '—' }} ms</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="runs.links?.length" class="flex flex-wrap gap-1">
      <component
        :is="link.url ? 'a' : 'span'"
        v-for="(link, index) in runs.links"
        :key="index"
        :href="link.url"
        class="rounded-md border px-2.5 py-1 text-xs"
        :class="link.active
          ? 'border-ink-900 bg-ink-900 text-white'
          : 'border-ink-300 bg-white text-ink-600 hover:bg-ink-50'"
        v-html="link.label"
      />
    </div>
  </PageHeader>
</template>
