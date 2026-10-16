<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'

const props = defineProps({
  datasets: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  lastCopies: { type: Array, default: () => [] },
})

const form = useForm({ dataset_id: null, target_id: null })

function copyNow() {
  if (!form.dataset_id || !form.target_id) return

  form.post('/copy')
}

function targetFor(id) {
  return props.targets.find((target) => target.id === Number(id))
}
</script>

<template>
  <Head title="Copy A → B" />

  <PageHeader title="Copy PostgreSQL A → B">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">
        Struktur dan isi PG B dibuat identik dengan PG A. Oracle tidak disentuh sama sekali.
      </p>
    </template>

    <div class="grid gap-6 lg:grid-cols-3">
      <form class="space-y-4 rounded-lg border border-slate-200 bg-white p-4 lg:col-span-2" @submit.prevent="copyNow">
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Tabel dari PostgreSQL A</span>
          <select v-model="form.dataset_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option :value="null" disabled>Pilih dataset…</option>
            <option v-for="dataset in datasets" :key="dataset.id" :value="dataset.id" :disabled="!dataset.has_data">
              {{ dataset.name }} — {{ dataset.destination }}
              {{ dataset.has_data ? '' : '(belum pernah sync)' }}
            </option>
          </select>
          <span v-if="form.errors.dataset_id" class="mt-1 block text-xs text-red-600">{{ form.errors.dataset_id }}</span>
        </label>

        <label class="block">
          <span class="text-sm font-medium text-slate-700">Tujuan PostgreSQL B</span>
          <select v-model="form.target_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option :value="null" disabled>Pilih target…</option>
            <option v-for="target in targets" :key="target.id" :value="target.id">
              {{ target.name }} — aplikasi {{ target.app_name }}
            </option>
          </select>
          <span v-if="form.errors.target_id" class="mt-1 block text-xs text-red-600">{{ form.errors.target_id }}</span>
        </label>

        <p
          v-if="form.target_id && !targetFor(form.target_id)?.has_readonly"
          class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"
        >
          Target ini belum punya user read-only. Aplikasi sebaiknya hanya membaca lewat user read-only.
        </p>

        <button type="submit"
                class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                :disabled="form.processing">
          {{ form.processing ? 'Menyalin…' : 'Copy' }}
        </button>
      </form>

      <aside class="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold">Target PG B</h2>
        <p v-if="targets.length === 0" class="text-xs text-slate-500">
          Belum ada PG B. Tambahkan lewat halaman Driver.
        </p>
        <div v-for="target in targets" :key="target.id" class="border-b border-slate-100 pb-2 last:border-0 last:pb-0">
          <p class="text-sm font-medium">{{ target.app_name }}</p>
          <p class="text-xs text-slate-500">{{ target.name }}</p>
          <p class="mt-1 text-xs" :class="target.has_readonly ? 'text-emerald-700' : 'text-amber-700'">
            {{ target.has_readonly
              ? `user read-only: ${target.readonly_username}`
              : 'belum ada user read-only' }}
          </p>
        </div>
      </aside>
    </div>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Copy terakhir</h2>
      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Waktu</th>
              <th class="px-3 py-2">Dataset</th>
              <th class="px-3 py-2">Target</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Verifikasi</th>
              <th class="px-3 py-2 text-right">Baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="lastCopies.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-slate-500">Belum ada copy.</td>
            </tr>
            <tr v-for="run in lastCopies" :key="run.id" class="border-t border-slate-100">
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.finished_at || '—' }}</td>
              <td class="px-3 py-2">{{ run.dataset }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ run.target }}</td>
              <td class="px-3 py-2 text-xs">{{ run.status }}</td>
              <td class="px-3 py-2">
                <VerifyBadge :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null" compact />
              </td>
              <td class="px-3 py-2 text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
