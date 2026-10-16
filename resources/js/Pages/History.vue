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
      <p class="mt-1 text-sm text-slate-500">
        Waktu, durasi, jumlah baris, status, pemicu, dan hasil verifikasi hash setiap eksekusi.
      </p>
    </template>

    <div class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-4">
      <label class="block">
        <span class="text-xs text-slate-600">Dataset</span>
        <select v-model="form.dataset_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="">Semua</option>
          <option v-for="(name, id) in datasets" :key="id" :value="id">{{ name }}</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs text-slate-600">Status</span>
        <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="">Semua</option>
          <option value="success">Sukses</option>
          <option value="failed">Gagal</option>
          <option value="skipped">Dilewati</option>
          <option value="paused">Di-pause</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs text-slate-600">Jenis</span>
        <select v-model="form.kind" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="">Semua</option>
          <option value="sync">Sync</option>
          <option value="copy">Copy</option>
          <option value="reconcile">Rekonsiliasi</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs text-slate-600">Verifikasi</span>
        <select v-model="form.verification" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          <option value="">Semua</option>
          <option value="ok">✅ Identik</option>
          <option value="failed">❌ Tidak identik</option>
        </select>
      </label>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-3 py-2">Waktu</th>
            <th class="px-3 py-2">Dataset</th>
            <th class="px-3 py-2">Jenis / pemicu</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2">Verifikasi</th>
            <th class="px-3 py-2 text-right">Baris</th>
            <th class="px-3 py-2 text-right">Durasi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="runs.data.length === 0">
            <td colspan="7" class="px-3 py-6 text-center text-slate-500">Belum ada eksekusi.</td>
          </tr>
          <tr v-for="run in runs.data" :key="run.id" class="border-t border-slate-100 align-top">
            <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ run.started_at }}</td>
            <td class="px-3 py-2">
              <Link :href="`/history/${run.id}`" class="hover:underline">{{ run.dataset || '—' }}</Link>
            </td>
            <td class="px-3 py-2 text-xs text-slate-500">
              {{ run.kind }} / {{ run.trigger }}
              <span v-if="run.triggered_by" class="block text-slate-400">{{ run.triggered_by }}</span>
            </td>
            <td class="px-3 py-2"><StatusBadge :status="run.status" /></td>
            <td class="px-3 py-2">
              <VerifyBadge
                :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null"
                :detail="run.verification_detail"
              />
              <p v-if="run.error_message" class="mt-1 max-w-xs text-xs text-red-600">{{ run.error_message }}</p>
            </td>
            <td class="px-3 py-2 text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            <td class="px-3 py-2 text-right text-xs text-slate-500">{{ run.duration_ms ?? '—' }} ms</td>
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
        class="rounded border border-slate-300 px-2.5 py-1 text-xs"
        :class="link.active ? 'bg-slate-900 text-white' : 'text-slate-600'"
        v-html="link.label"
      />
    </div>
  </PageHeader>
</template>
