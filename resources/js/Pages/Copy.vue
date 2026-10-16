<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import WarningList from '../Components/WarningList.vue'

const props = defineProps({
  datasets: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  lastCopies: { type: Array, default: () => [] },
})

const form = useForm({ dataset_id: null, target_id: null })

const selectedTarget = computed(() => props.targets.find((t) => t.id === Number(form.target_id)))

function copyNow() {
  if (!form.dataset_id || !form.target_id) return
  form.post('/copy')
}
</script>

<template>
  <Head title="Copy A → B" />

  <PageHeader title="Copy PostgreSQL A → B">
    <template #subtitle>
      Struktur dan isi PG B dibuat identik dengan PG A. Oracle tidak disentuh sama sekali.
    </template>

    <div class="grid gap-4 lg:grid-cols-3">
      <form class="surface space-y-4 p-5 lg:col-span-2" @submit.prevent="copyNow">
        <label class="block">
          <span class="label">Tabel dari PostgreSQL A</span>
          <select v-model="form.dataset_id" required class="input mt-1.5">
            <option :value="null" disabled>Pilih dataset…</option>
            <option v-for="dataset in datasets" :key="dataset.id" :value="dataset.id" :disabled="!dataset.has_data">
              {{ dataset.name }} — {{ dataset.destination }}{{ dataset.has_data ? '' : ' (belum pernah sync)' }}
            </option>
          </select>
          <span v-if="form.errors.dataset_id" class="hint text-red-600">{{ form.errors.dataset_id }}</span>
        </label>

        <label class="block">
          <span class="label">Tujuan PostgreSQL B</span>
          <select v-model="form.target_id" required class="input mt-1.5">
            <option :value="null" disabled>Pilih target…</option>
            <option v-for="target in targets" :key="target.id" :value="target.id">
              {{ target.name }} — aplikasi {{ target.app_name }}
            </option>
          </select>
          <span v-if="form.errors.target_id" class="hint text-red-600">{{ form.errors.target_id }}</span>
        </label>

        <WarningList
          v-if="selectedTarget && !selectedTarget.has_readonly"
          tone="amber"
          title="Belum ada user read-only"
          :warnings="['Aplikasi sebaiknya hanya membaca lewat user read-only, bukan user owner. Jalankan perintah SQL read-only dari README.']"
        />

        <div class="flex items-center gap-3 border-t border-ink-100 pt-4">
          <button type="submit" class="btn-primary" :disabled="form.processing || !form.dataset_id || !form.target_id">
            {{ form.processing ? 'Menyalin…' : 'Copy' }}
          </button>
          <span class="text-xs text-ink-400">PG B yang rusak bisa di-copy ulang dari A kapan saja.</span>
        </div>
      </form>

      <aside class="surface p-5">
        <h2 class="eyebrow">Target PG B</h2>
        <p v-if="targets.length === 0" class="mt-3 text-xs text-ink-400">
          Belum ada PG B. Tambahkan lewat halaman Driver.
        </p>
        <ul v-else class="mt-3 space-y-3">
          <li v-for="target in targets" :key="target.id" class="rounded-lg border border-ink-100 p-3">
            <p class="text-sm font-medium text-ink-800">{{ target.app_name }}</p>
            <p class="mt-0.5 text-xs text-ink-500">{{ target.name }}</p>
            <p class="mt-1.5 text-xs" :class="target.has_readonly ? 'text-emerald-700' : 'text-amber-700'">
              {{ target.has_readonly ? `user read-only: ${target.readonly_username}` : 'belum ada user read-only' }}
            </p>
          </li>
        </ul>
      </aside>
    </div>

    <section class="space-y-3">
      <h2 class="eyebrow">Copy terakhir</h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Dataset</th>
              <th>Target</th>
              <th>Status</th>
              <th>Verifikasi</th>
              <th class="text-right">Baris</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="lastCopies.length === 0">
              <td colspan="6" class="py-10 text-center text-ink-400">Belum ada copy.</td>
            </tr>
            <tr v-for="run in lastCopies" :key="run.id">
              <td class="whitespace-nowrap text-xs text-ink-500">{{ run.finished_at || '—' }}</td>
              <td class="font-medium">{{ run.dataset }}</td>
              <td class="text-xs text-ink-500">{{ run.target }}</td>
              <td class="text-xs">{{ run.status }}</td>
              <td>
                <VerifyBadge :matched="run.verification === 'ok' ? true : run.verification === 'failed' ? false : null" compact />
              </td>
              <td class="text-right tabular-nums">{{ run.row_count ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
