<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import StatusBadge from '../Components/StatusBadge.vue'
import VerifyBadge from '../Components/VerifyBadge.vue'
import WarningList from '../Components/WarningList.vue'
import EmptyState from '../Components/EmptyState.vue'
import Field from '../Components/Field.vue'
import Term from '../Components/Term.vue'
import Icon from '../Components/Icon.vue'
import { confirm } from '../lib/confirm.js'
import { errorList, formatNumber, timeAgo, verificationState } from '../lib/terms.js'

const props = defineProps({
  datasets: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  lastCopies: { type: Array, default: () => [] },
})

const page = usePage()
const isAdmin = computed(() => page.props.auth?.is_admin === true)

const form = useForm({ dataset_id: null, target_id: null })

const readyDatasets = computed(() => props.datasets.filter((d) => d.has_data))
const notReady = computed(() => props.datasets.filter((d) => !d.has_data))
const selectedDataset = computed(() => props.datasets.find((d) => d.id === Number(form.dataset_id)))
const selectedTarget = computed(() => props.targets.find((t) => t.id === Number(form.target_id)))

async function copyNow() {
  if (!selectedDataset.value || !selectedTarget.value) return

  const ok = await confirm({
    title: `Kirim ke ${selectedTarget.value.app_name}?`,
    message: `Tabel ${selectedDataset.value.destination} di Database Aplikasi "${selectedTarget.value.name}" akan diganti sehingga isinya sama persis dengan Database Penampung.`,
    details: [
      'Isi lama tabel tersebut di Database Aplikasi akan tertimpa.',
      'Oracle tidak disentuh sama sekali.',
      'Jika terjadi masalah, kirim ulang kapan saja — Database Penampung tetap utuh.',
    ],
    confirmLabel: 'Ya, kirim sekarang',
  })

  if (ok) form.post('/copy', { preserveScroll: true })
}

const otherErrors = computed(() => errorList(form.errors, ['dataset_id', 'target_id']))
</script>

<template>
  <Head title="Kirim ke Aplikasi" />

  <PageHeader title="Kirim ke Aplikasi">
    <template #subtitle>
      Salin tabel dari <Term term="pgA" /> ke <Term term="pgB" /> yang dibaca aplikasi Anda.
      Hasilnya identik dan diperiksa otomatis.
    </template>

    <div class="grid gap-6 lg:grid-cols-5">
      <!-- Form kirim -->
      <form class="surface space-y-6 p-6 sm:p-8 lg:col-span-3" @submit.prevent="copyNow">
        <Field label="Tabel yang dikirim" :error="form.errors.dataset_id">
          <select v-model="form.dataset_id" required class="input" :disabled="readyDatasets.length === 0">
            <option :value="null" disabled>Pilih dataset…</option>
            <option v-for="dataset in readyDatasets" :key="dataset.id" :value="dataset.id">
              {{ dataset.name }} — {{ dataset.destination }}
            </option>
          </select>
          <template v-if="notReady.length" #hint>
            {{ notReady.length }} dataset belum muncul karena datanya belum pernah diambil:
            {{ notReady.map((d) => d.name).join(', ') }}.
          </template>
        </Field>

        <div class="flex justify-center text-ink-300" aria-hidden="true">
          <Icon name="arrow" :size="22" class="rotate-90" />
        </div>

        <Field label="Kirim ke aplikasi" :error="form.errors.target_id">
          <div v-if="targets.length" class="grid gap-3 sm:grid-cols-2">
            <label v-for="target in targets" :key="target.id" class="choice">
              <input v-model="form.target_id" type="radio" :value="target.id" class="mt-1 accent-brand-600">
              <span class="min-w-0">
                <span class="block font-semibold text-ink-900">{{ target.app_name }}</span>
                <span class="block truncate text-sm text-ink-500">{{ target.name }}</span>
                <span class="mt-1.5 inline-flex items-center gap-1 text-xs" :class="target.has_readonly ? 'text-emerald-800' : 'text-amber-800'">
                  <Icon :name="target.has_readonly ? 'shield' : 'alert'" :size="14" />
                  {{ target.has_readonly ? 'Akun baca-saja tersedia' : 'Belum ada akun baca-saja' }}
                </span>
              </span>
            </label>
          </div>
          <WarningList v-else title="Belum ada Database Aplikasi">
            <template v-if="isAdmin">Tambahkan di halaman <Link href="/drivers?tab=targets" class="font-semibold underline">Koneksi</Link>.</template>
            <template v-else>Minta admin menambahkannya terlebih dahulu.</template>
          </WarningList>
        </Field>

        <WarningList v-if="selectedTarget && !selectedTarget.has_readonly" title="Disarankan memakai akun baca-saja">
          Aplikasi sebaiknya membaca data dengan <Term term="readonly" label="akun baca-saja" />, bukan akun pemilik database.
          <template v-if="isAdmin">Atur di <Link href="/drivers?tab=targets" class="font-semibold underline">Koneksi → Aplikasi tujuan</Link>.</template>
        </WarningList>

        <WarningList v-if="otherErrors.length" tone="red" title="Gagal mengirim" :warnings="otherErrors" />

        <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 pt-6">
          <button type="submit" class="btn-primary" :disabled="form.processing || !form.dataset_id || !form.target_id">
            <Icon :name="form.processing ? 'refresh' : 'send'" :class="form.processing ? 'animate-spin' : ''" />
            {{ form.processing ? 'Mengirim…' : 'Kirim sekarang' }}
          </button>
          <span class="text-sm text-ink-500">Anda akan diminta konfirmasi dulu.</span>
        </div>
      </form>

      <!-- Penjelasan -->
      <aside class="surface-quiet space-y-4 p-6 lg:col-span-2">
        <h2 class="font-semibold text-ink-900">Cara kerjanya</h2>
        <ol class="space-y-4 text-sm text-ink-700">
          <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-bold text-brand-700 ring-1 ring-ink-200">1</span>
            <span>Struktur dan isi tabel dari Database Penampung disalin utuh.</span>
          </li>
          <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-bold text-brand-700 ring-1 ring-ink-200">2</span>
            <span>Tabel di Database Aplikasi diganti dalam sekali langkah, jadi aplikasi tidak melihat data setengah jadi.</span>
          </li>
          <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-bold text-brand-700 ring-1 ring-ink-200">3</span>
            <span>Isi kedua tabel dibandingkan. Hasilnya tampil sebagai <strong>Data cocok</strong> atau <strong>Data tidak cocok</strong>.</span>
          </li>
        </ol>
        <p class="rounded-xl bg-surface p-3.5 text-sm text-ink-600 ring-1 ring-ink-200">
          Ingin otomatis? Buka dataset, lalu di langkah 4 centang aplikasi tujuan.
        </p>
      </aside>
    </div>

    <section class="space-y-4">
      <h2 class="section-title">Pengiriman terakhir</h2>
      <div class="table-wrap">
        <EmptyState v-if="lastCopies.length === 0" icon="send" title="Belum ada pengiriman" text="Riwayat pengiriman ke Database Aplikasi akan tampil di sini." />
        <table v-else class="table">
          <thead>
            <tr><th>Waktu</th><th>Dataset</th><th>Ke</th><th>Hasil</th><th>Pemeriksaan</th><th class="text-right">Baris</th></tr>
          </thead>
          <tbody>
            <tr v-for="run in lastCopies" :key="run.id">
              <td class="text-sm whitespace-nowrap">
                <Link :href="`/history/${run.id}`" class="text-ink-700 hover:text-brand-700" :title="run.finished_at">{{ timeAgo(run.finished_at) }}</Link>
              </td>
              <td class="font-semibold text-ink-900">{{ run.dataset }}</td>
              <td class="text-sm">{{ run.target }}</td>
              <td><StatusBadge :status="run.status" /></td>
              <td><VerifyBadge :matched="verificationState(run.verification)" compact /></td>
              <td class="text-right tabular-nums">{{ formatNumber(run.row_count) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>
</template>
