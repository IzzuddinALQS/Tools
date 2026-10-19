<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'
import Modal from '../Components/Modal.vue'
import Field from '../Components/Field.vue'
import Term from '../Components/Term.vue'
import Icon from '../Components/Icon.vue'
import EmptyState from '../Components/EmptyState.vue'
import WarningList from '../Components/WarningList.vue'
import ErrorMessage from '../Components/ErrorMessage.vue'
import { confirm } from '../lib/confirm.js'
import { TERMS, errorList, timeAgo } from '../lib/terms.js'

const props = defineProps({
  connections: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  defaults: { type: Object, required: true },
  tokens: { type: Array, default: () => [] },
  tokenableDatasets: { type: Array, default: () => [] },
  /*
   * Token yang baru terbit. Yang masuk ke session cuma ciphertext; plaintext
   * didekripsi di server sebelum sampai ke sini, dan hanya untuk render ini.
   */
  issuedToken: { type: Object, default: null },
})

const page = usePage()

/* ---------------- Tab ---------------- */
const tabs = computed(() => [
  { key: 'targets', label: 'Aplikasi tujuan', icon: 'send', count: props.targets.length },
  { key: 'sources', label: 'Sumber koneksi', icon: 'database', count: props.connections.length },
  { key: 'tokens', label: 'Akses API', icon: 'key', count: props.tokens.filter((t) => t.is_active).length },
])
const tab = ref('targets')

onMounted(() => {
  const wanted = new URLSearchParams(window.location.search).get('tab')
  if (props.issuedToken) tab.value = 'tokens'
  else if (tabs.value.some((t) => t.key === wanted)) tab.value = wanted
})

watch(tab, (value) => {
  const url = new URL(window.location.href)
  url.searchParams.set('tab', value)
  window.history.replaceState(window.history.state, '', url)
})

const pageErrors = computed(() => (modal.value ? [] : errorList(page.props.errors)))

/* ---------------- Modal ---------------- */
const modal = ref(null) // 'connection' | 'target' | 'token'
const editing = ref(null)

function closeModal() {
  modal.value = null
  editing.value = null
}

/* ---------------- Sumber Oracle ---------------- */
const blankConnection = () => ({
  name: '', description: '', driver: props.defaults.driver,
  host: '', port: props.defaults.oracle_port,
  service_name: '', sid: '', tns_alias: '', dsn: '',
  username: '', password: '', fixture_set: 'armors', is_active: true,
})

const connection = useForm(blankConnection())
const connectBy = ref('service_name')

function openConnection(item = null) {
  connection.clearErrors()
  editing.value = item
  const data = item
    ? { ...blankConnection(), ...item, password: '', description: item.description ?? '' }
    : blankConnection()

  for (const key of Object.keys(blankConnection())) connection[key] = data[key] ?? ''
  connection.port = data.port || props.defaults.oracle_port
  connectBy.value = data.sid ? 'sid' : data.tns_alias ? 'tns_alias' : 'service_name'
  modal.value = 'connection'
}

watch(connectBy, (by) => {
  for (const key of ['service_name', 'sid', 'tns_alias']) {
    if (key !== by) connection[key] = ''
  }
})

function saveConnection() {
  const options = { preserveScroll: true, onSuccess: closeModal }
  if (editing.value) connection.put(`/drivers/connections/${editing.value.id}`, options)
  else connection.post('/drivers/connections', options)
}

/* ---------------- Aplikasi tujuan ---------------- */
const hasPgA = computed(() => props.targets.some((t) => t.kind === 'pg_a'))

const blankTarget = () => ({
  name: '', description: '', kind: hasPgA.value ? 'pg_b' : 'pg_a', app_name: '',
  host: '', port: props.defaults.pg_port, database: '', username: '',
  password: '', sslmode: 'prefer',
  readonly_username: '', readonly_password: '', auto_provision_readonly: false,
  is_active: true,
})

const target = useForm(blankTarget())

function openTarget(item = null, kind = null) {
  target.clearErrors()
  editing.value = item
  const data = item
    ? { ...blankTarget(), ...item, password: '', readonly_password: '', description: item.description ?? '' }
    : { ...blankTarget(), ...(kind ? { kind } : {}) }

  for (const key of Object.keys(blankTarget())) target[key] = data[key] ?? ''
  target.auto_provision_readonly = Boolean(data.auto_provision_readonly)
  target.is_active = data.is_active !== false
  modal.value = 'target'
}

function saveTarget() {
  const options = { preserveScroll: true, onSuccess: closeModal }
  if (editing.value) target.put(`/drivers/targets/${editing.value.id}`, options)
  else target.post('/drivers/targets', options)
}

/* ---------------- Tes & hapus ---------------- */
const testing = ref(null)

function test(kind, item) {
  testing.value = `${kind}-${item.id}`
  router.post(`/drivers/${kind}/${item.id}/test`, {}, {
    preserveScroll: true,
    onFinish: () => { testing.value = null },
  })
}

async function remove(kind, item) {
  const ok = await confirm({
    title: `Hapus "${item.name}"?`,
    message: kind === 'connections'
      ? 'Sumber yang masih dipakai dataset tidak bisa dihapus.'
      : 'Aplikasi tujuan yang masih dipakai dataset tidak bisa dihapus. Isi database-nya sendiri tidak disentuh.',
    confirmLabel: 'Hapus',
    tone: 'danger',
  })
  if (ok) router.delete(`/drivers/${kind}/${item.id}`, { preserveScroll: true })
}

/* ---------------- Akses API ---------------- */
const revoking = ref(null)
const copied = ref(null)
const tokenDismissed = ref(false)

const token = useForm({
  name: '', app_name: '', dataset_id: null,
  rate_limit_per_minute: 10, allowed_ips: '',
})

const revealed = computed(() => (tokenDismissed.value ? null : props.issuedToken ?? null))

watch(
  () => props.issuedToken?.uid,
  () => { tokenDismissed.value = false },
)

function openToken() {
  token.reset()
  token.clearErrors()
  modal.value = 'token'
}

function issueToken() {
  token.post('/drivers/tokens', {
    preserveScroll: true,
    onSuccess: () => {
      closeModal()
      tab.value = 'tokens'
    },
  })
}

async function revokeToken(item) {
  const ok = await confirm({
    title: `Cabut akses untuk "${item.app_name}"?`,
    message: 'Aplikasi langsung ditolak pada permintaan berikutnya.',
    details: ['Kunci yang sudah dicabut tidak bisa diaktifkan lagi — buat kunci baru jika diperlukan.'],
    confirmLabel: 'Cabut akses',
    tone: 'danger',
  })
  if (!ok) return

  revoking.value = item.uid
  router.post(`/drivers/tokens/${item.id}/revoke`, { reason: 'Dicabut dari halaman Koneksi' }, {
    preserveScroll: true,
    onFinish: () => { revoking.value = null },
  })
}

function copy(text, key) {
  navigator.clipboard?.writeText(text)
  copied.value = key
  window.setTimeout(() => { copied.value = null }, 2000)
}

const endpoint = computed(() => revealed.value
  ? `/api/v1/targets/${revealed.value.target_uid}/datasets/${revealed.value.dataset_uid}/refresh`
  : '')

const extraErrors = (form, known) => errorList(form.errors, known)
</script>

<template>
  <Head title="Koneksi" />

  <PageHeader title="Koneksi">
    <template #subtitle>
      Atur dari mana data dibaca dan ke mana data dikirim. Halaman ini hanya untuk admin.
      Password disimpan terenkripsi dan tidak pernah ditampilkan lagi.
    </template>

    <WarningList tone="info" title="Keamanan utama ada di Oracle">
      Mintalah DBA membuat akun Oracle yang <strong>hanya punya hak SELECT</strong>. Tool ini tidak pernah menulis ke Oracle,
      tetapi akun baca-saja menjamin hal itu dari sisi Oracle sendiri.
    </WarningList>

    <!-- Tab -->
    <div class="flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-ink-200)]" role="tablist">
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        role="tab"
        :aria-selected="tab === item.key"
        class="inline-flex items-center gap-2 border-b-2 px-4 py-3 text-[15px] font-semibold whitespace-nowrap transition"
        :class="tab === item.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-500 hover:text-ink-800'"
        @click="tab = item.key"
      >
        <Icon :name="item.icon" />
        {{ item.label }}
        <span class="rounded-full px-2 text-xs" :class="tab === item.key ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-600'">{{ item.count }}</span>
      </button>
    </div>

    <WarningList v-if="pageErrors.length" tone="red" title="Ada masalah">
      <ErrorMessage v-for="(message, index) in pageErrors" :key="index" :message="message" />
    </WarningList>

    <!-- ================= Aplikasi tujuan ================= -->
    <section v-if="tab === 'targets'" class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-ink-600">
          Satu <Term term="pgA" /> menyimpan hasil query, lalu satu <Term term="pgB" /> untuk setiap aplikasi.
        </p>
        <button type="button" class="btn-primary" @click="openTarget()"><Icon name="plus" /> Tambah database</button>
      </div>

      <WarningList v-if="!hasPgA" title="Mulai dengan Database Penampung">
        Database Penampung wajib ada sebelum dataset bisa dibuat.
        <button type="button" class="font-semibold underline" @click="openTarget(null, 'pg_a')">Tambahkan sekarang</button>
      </WarningList>

      <div v-if="targets.length" class="grid gap-4 md:grid-cols-2">
        <article v-for="item in targets" :key="item.id" class="surface flex flex-col p-6" :class="item.kind === 'pg_a' ? 'border-brand-200' : ''">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-lg font-semibold text-ink-900">{{ item.kind === 'pg_b' ? item.app_name : item.name }}</p>
              <p class="mono mt-0.5 truncate text-ink-500">{{ item.host }}:{{ item.port }} / {{ item.database }}</p>
            </div>
            <span :class="item.kind === 'pg_a' ? 'badge-info' : 'badge-neutral'">
              {{ item.kind === 'pg_a' ? TERMS.pgA.label : TERMS.pgB.label }}
            </span>
          </div>

          <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div v-if="item.kind === 'pg_b'">
              <dt class="text-ink-500"><Term term="readonly" /></dt>
              <dd class="mt-0.5">
                <span v-if="item.readonly_username" class="mono text-ink-800">{{ item.readonly_username }}</span>
                <span v-else class="font-medium text-amber-800">Belum diatur</span>
              </dd>
            </div>
            <div v-else>
              <dt class="text-ink-500">Nama</dt>
              <dd class="mt-0.5 text-ink-800">{{ item.name }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Tes terakhir</dt>
              <dd class="mt-0.5">
                <span v-if="item.last_test_status === 'ok'" class="inline-flex items-center gap-1 font-medium text-emerald-800"><Icon name="check" :size="14" /> Terhubung</span>
                <span v-else-if="item.last_test_status" class="inline-flex items-center gap-1 font-medium text-red-700"><Icon name="alert" :size="14" /> Gagal</span>
                <span v-else class="text-ink-500">Belum pernah dites</span>
                <span v-if="item.last_tested_at" class="block text-xs text-ink-500">{{ timeAgo(item.last_tested_at) }}</span>
              </dd>
            </div>
          </dl>

          <div v-if="item.last_test_status && item.last_test_status !== 'ok' && item.last_test_message" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-800">
            <ErrorMessage :message="item.last_test_message" />
          </div>

          <div class="mt-auto flex flex-wrap gap-2 border-t border-ink-100 pt-4">
            <button type="button" class="btn-secondary btn-sm" :disabled="testing === `targets-${item.id}`" @click="test('targets', item)">
              <Icon name="refresh" :size="16" :class="testing === `targets-${item.id}` ? 'animate-spin' : ''" />
              {{ testing === `targets-${item.id}` ? 'Mengetes…' : 'Tes koneksi' }}
            </button>
            <button type="button" class="btn-ghost btn-sm" @click="openTarget(item)"><Icon name="edit" :size="16" /> Ubah</button>
            <button type="button" class="btn-ghost btn-sm ml-auto text-red-700 hover:bg-red-50 hover:text-red-800" @click="remove('targets', item)">
              <Icon name="trash" :size="16" /> Hapus
            </button>
          </div>
        </article>
      </div>
    </section>

    <!-- ================= Sumber Oracle ================= -->
    <section v-if="tab === 'sources'" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-ink-600">Database Oracle yang datanya ingin Anda salin.</p>
        <button type="button" class="btn-primary" @click="openConnection()"><Icon name="plus" /> Tambah Koneksi</button>
      </div>

      <div v-if="connections.length === 0" class="surface">
        <EmptyState icon="database" title="Belum ada sumber" text="Tambahkan koneksi Oracle. Belum punya akses Oracle? Pilih jenis &quot;Data contoh&quot; untuk mencoba tool ini lebih dulu.">
          <button type="button" class="btn-primary" @click="openConnection()"><Icon name="plus" /> Tambah Koneksi</button>
        </EmptyState>
      </div>

      <div v-else class="grid gap-4 md:grid-cols-2">
        <article v-for="item in connections" :key="item.id" class="surface flex flex-col p-6">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-lg font-semibold text-ink-900">{{ item.name }}</p>
              <p class="mono mt-0.5 truncate text-ink-500">
                <template v-if="item.driver === 'oracle'">{{ item.host }}:{{ item.port }} / {{ item.service_name || item.sid || item.tns_alias }}</template>
                <template v-else>katalog: {{ item.fixture_set || 'armors' }}</template>
              </p>
            </div>
            <span :class="item.driver === 'oracle' ? 'badge-info' : 'badge-neutral'">
              {{ item.driver === 'oracle' ? 'Oracle' : TERMS.mock.label }}
            </span>
          </div>

          <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div>
              <dt class="text-ink-500">Akun</dt>
              <dd class="mono mt-0.5 text-ink-800">{{ item.username || '—' }}</dd>
            </div>
            <div>
              <dt class="text-ink-500">Tes terakhir</dt>
              <dd class="mt-0.5">
                <span v-if="item.last_test_status === 'ok'" class="inline-flex items-center gap-1 font-medium text-emerald-800"><Icon name="check" :size="14" /> Terhubung</span>
                <span v-else-if="item.last_test_status" class="inline-flex items-center gap-1 font-medium text-red-700"><Icon name="alert" :size="14" /> Gagal</span>
                <span v-else class="text-ink-500">Belum pernah dites</span>
                <span v-if="item.last_tested_at" class="block text-xs text-ink-500">{{ timeAgo(item.last_tested_at) }}</span>
              </dd>
            </div>
          </dl>

          <div v-if="item.last_test_status && item.last_test_status !== 'ok' && item.last_test_message" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-800">
            <ErrorMessage :message="item.last_test_message" />
          </div>

          <div class="mt-auto flex flex-wrap gap-2 border-t border-ink-100 pt-4">
            <button type="button" class="btn-secondary btn-sm" :disabled="testing === `connections-${item.id}`" @click="test('connections', item)">
              <Icon name="refresh" :size="16" :class="testing === `connections-${item.id}` ? 'animate-spin' : ''" />
              {{ testing === `connections-${item.id}` ? 'Mengetes…' : 'Tes koneksi' }}
            </button>
            <button type="button" class="btn-ghost btn-sm" @click="openConnection(item)"><Icon name="edit" :size="16" /> Ubah</button>
            <button type="button" class="btn-ghost btn-sm ml-auto text-red-700 hover:bg-red-50 hover:text-red-800" @click="remove('connections', item)">
              <Icon name="trash" :size="16" /> Hapus
            </button>
          </div>
        </article>
      </div>
    </section>
    
    <!-- ================= Akses API ================= -->
    <section v-if="tab === 'tokens'" class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-ink-600">{{ TERMS.apiToken.help }}</p>
        <button type="button" class="btn-primary" :disabled="tokenableDatasets.length === 0" @click="openToken"><Icon name="plus" /> Buat kunci akses</button>
      </div>

      <!-- Kunci yang baru dibuat: nilai asli hanya tampil sekali -->
      <div v-if="revealed" class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-6">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="font-semibold text-emerald-900">Kunci akses untuk {{ revealed.app_name }} sudah dibuat</p>
            <p class="mt-1 text-sm text-emerald-800">
              <strong>Salin sekarang.</strong> Kunci ini hanya ditampilkan sekali. Setelah halaman dimuat ulang, kunci tidak bisa dilihat lagi —
              hanya bisa dicabut dan dibuat ulang.
            </p>
          </div>
          <button type="button" class="btn-ghost btn-xs shrink-0" @click="tokenDismissed = true">Tutup</button>
        </div>

        <div class="mt-5 space-y-4">
          <div>
            <span class="label">Kunci akses</span>
            <div class="mt-1.5 flex items-center gap-2">
              <code class="mono flex-1 overflow-x-auto rounded-xl border border-emerald-300 bg-surface px-3.5 py-2.5 text-ink-900">{{ revealed.plain }}</code>
              <button type="button" class="btn-secondary btn-sm shrink-0" @click="copy(revealed.plain, 'plain')">
                <Icon :name="copied === 'plain' ? 'check' : 'copy'" :size="16" /> {{ copied === 'plain' ? 'Tersalin' : 'Salin' }}
              </button>
            </div>
          </div>

          <div>
            <span class="label">Alamat yang dipanggil aplikasi</span>
            <div class="mt-1.5 flex items-center gap-2">
              <!--
                Verb HTTP sengaja TIDAK ikut di dalam blok kode. Kalau ikut,
                menyalin teks secara manual menghasilkan URL yang rusak karena
                diawali "POST ".
              -->
              <code class="mono flex-1 overflow-x-auto rounded-xl border border-emerald-300 bg-surface px-3.5 py-2.5 text-ink-900">{{ endpoint }}</code>
              <button type="button" class="btn-secondary btn-sm shrink-0" @click="copy(endpoint, 'url')">
                <Icon :name="copied === 'url' ? 'check' : 'copy'" :size="16" /> {{ copied === 'url' ? 'Tersalin' : 'Salin' }}
              </button>
            </div>
            <p class="hint">
              Gunakan method <span class="kbd">POST</span> dengan header <span class="kbd">Authorization: Bearer &lt;kunci akses&gt;</span>
            </p>
          </div>
        </div>
      </div>

      <div class="table-wrap">
        <EmptyState
          v-if="tokens.length === 0"
          icon="key"
          title="Belum ada kunci akses"
          :text="tokenableDatasets.length ? 'Buat kunci agar aplikasi lain bisa memicu pengambilan data satu dataset.' : 'Buat dataset terlebih dahulu sebelum membuat kunci akses.'"
        />
        <table v-else class="table">
          <thead>
            <tr><th>Aplikasi</th><th>Dataset</th><th>Batas</th><th>Terakhir dipakai</th><th>Status</th><th><span class="sr-only">Aksi</span></th></tr>
          </thead>
          <tbody>
            <tr v-for="item in tokens" :key="item.id" :class="!item.is_active ? 'opacity-60' : ''">
              <td>
                <p class="font-semibold text-ink-900">{{ item.app_name }}</p>
                <p class="text-xs text-ink-500">{{ item.name }}</p>
              </td>
              <td class="text-sm">
                {{ item.dataset || '—' }}
                <span v-if="item.allowed_ips?.length" class="block text-xs text-ink-500">hanya dari IP {{ item.allowed_ips.join(', ') }}</span>
              </td>
              <td class="text-sm whitespace-nowrap tabular-nums">{{ item.rate_limit_per_minute }}× / menit</td>
              <td class="text-sm whitespace-nowrap text-ink-600" :title="item.last_used_at">{{ item.last_used_at ? timeAgo(item.last_used_at) : 'Belum pernah' }}</td>
              <td>
                <span v-if="item.is_active" class="badge-success">Aktif</span>
                <span v-else class="badge-neutral" :title="item.revoked_reason">Dicabut</span>
              </td>
              <td class="text-right">
                <button v-if="item.is_active" type="button" class="btn-danger btn-xs" :disabled="revoking === item.uid" @click="revokeToken(item)">
                  {{ revoking === item.uid ? 'Mencabut…' : 'Cabut' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PageHeader>

  <!-- ============ Modal: sumber Oracle ============ -->
  <Modal :open="modal === 'connection'" :title="editing ? `Ubah sumber: ${editing.name}` : 'Tambah Koneksi Oracle'" size="lg" @close="closeModal">
    <form id="connection-form" class="space-y-6" @submit.prevent="saveConnection">
      <div>
        <span class="label">Jenis sumber</span>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
          <label v-for="driver in defaults.drivers" :key="driver.value" class="choice">
            <input v-model="connection.driver" type="radio" :value="driver.value" class="mt-1 accent-brand-600">
            <span>
              <span class="block font-semibold text-ink-900">{{ driver.value === 'oracle' ? 'Oracle sungguhan' : TERMS.mock.label }}</span>
              <span class="mt-0.5 block text-sm text-ink-600">
                {{ driver.value === 'oracle' ? 'Terhubung ke server Oracle Anda.' : 'Oracle tiruan berisi data contoh, untuk mencoba tanpa akses Oracle.' }}
              </span>
            </span>
          </label>
        </div>
      </div>

      <div class="grid gap-5 sm:grid-cols-2">
        <Field label="Nama koneksi" hint="Nama bebas agar mudah dikenali." :error="connection.errors.name" class="sm:col-span-2">
          <input v-model="connection.name" required class="input" placeholder="oracle-produksi">
        </Field>

        <template v-if="connection.driver === 'oracle'">
          <Field label="Alamat server (host)" :error="connection.errors.host">
            <input v-model="connection.host" required class="input mono" placeholder="oracle.perusahaan.local">
          </Field>
          <Field label="Port" hint="Biasanya 1521." :error="connection.errors.port">
            <input v-model.number="connection.port" type="number" class="input">
          </Field>

          <div class="sm:col-span-2">
            <span class="label">Cara menunjuk database</span>
            <p class="hint mt-0.5">Tanyakan ke DBA Anda jika ragu. Paling umum: Service name.</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="opt in [{ v: 'service_name', l: 'Service name' }, { v: 'sid', l: 'SID' }, { v: 'tns_alias', l: 'TNS alias' }]"
                :key="opt.v"
                type="button"
                class="rounded-full border px-3 py-1.5 text-[13px] font-medium transition"
                :class="connectBy === opt.v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 bg-surface text-ink-600 hover:border-ink-300'"
                @click="connectBy = opt.v"
              >
                {{ opt.l }}
              </button>
            </div>
            <input v-model="connection[connectBy]" class="input mono mt-3" :placeholder="connectBy === 'service_name' ? 'ORCLPDB1' : connectBy === 'sid' ? 'ORCL' : 'PROD_DB'" :aria-label="connectBy">
            <span v-if="connection.errors[connectBy]" class="error-text">{{ connection.errors[connectBy] }}</span>
          </div>

          <Field label="Username" hint="Akun dengan hak SELECT saja." :error="connection.errors.username">
            <input v-model="connection.username" class="input mono" autocomplete="off">
          </Field>
          <Field label="Password" :hint="editing ? 'Kosongkan jika tidak ingin mengubah password.' : 'Disimpan terenkripsi.'" :error="connection.errors.password">
            <input v-model="connection.password" type="password" :required="!editing" class="input" autocomplete="new-password" :placeholder="editing ? '••••••••  (tidak diubah)' : ''">
          </Field>
        </template>

        <template v-else>
          <Field label="Katalog data contoh" hint="Bawaan: armors." :error="connection.errors.fixture_set">
            <input v-model="connection.fixture_set" class="input mono" placeholder="armors">
          </Field>
          <Field label="Username" optional :error="connection.errors.username">
            <input v-model="connection.username" class="input mono">
          </Field>
        </template>

        <Field label="Keterangan" optional class="sm:col-span-2">
          <input v-model="connection.description" class="input">
        </Field>
      </div>

      <WarningList
        v-if="extraErrors(connection, ['name', 'host', 'port', 'service_name', 'sid', 'tns_alias', 'username', 'password', 'fixture_set']).length"
        tone="red"
        title="Belum bisa disimpan"
        :warnings="extraErrors(connection, ['name', 'host', 'port', 'service_name', 'sid', 'tns_alias', 'username', 'password', 'fixture_set'])"
      />
    </form>
    <template #footer>
      <button type="button" class="btn-secondary" @click="closeModal">Batal</button>
      <button type="submit" form="connection-form" class="btn-primary" :disabled="connection.processing">
        {{ connection.processing ? 'Menyimpan…' : editing ? 'Simpan perubahan' : 'Tambah Koneksi' }}
      </button>
    </template>
  </Modal>

  <!-- ============ Modal: aplikasi tujuan ============ -->
  <Modal :open="modal === 'target'" :title="editing ? `Ubah database: ${editing.name}` : 'Tambah aplikasi tujuan'" size="lg" @close="closeModal">
    <form id="target-form" class="space-y-6" @submit.prevent="saveTarget">
      <div>
        <span class="label">Peran database</span>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
          <label class="choice" :class="hasPgA && editing?.kind !== 'pg_a' ? 'pointer-events-none opacity-50' : ''">
            <input v-model="target.kind" type="radio" value="pg_a" class="mt-1 accent-brand-600" :disabled="hasPgA && editing?.kind !== 'pg_a'">
            <span>
              <span class="block font-semibold text-ink-900">{{ TERMS.pgA.label }}</span>
              <span class="mt-0.5 block text-sm text-ink-600">
                {{ hasPgA && editing?.kind !== 'pg_a' ? 'Sudah ada — hanya boleh satu.' : 'Tempat hasil query disimpan. Hanya satu.' }}
              </span>
            </span>
          </label>
          <label class="choice">
            <input v-model="target.kind" type="radio" value="pg_b" class="mt-1 accent-brand-600">
            <span>
              <span class="block font-semibold text-ink-900">{{ TERMS.pgB.label }}</span>
              <span class="mt-0.5 block text-sm text-ink-600">Dibaca oleh satu aplikasi. Boleh lebih dari satu.</span>
            </span>
          </label>
        </div>
        <span v-if="target.errors.kind" class="error-text">{{ target.errors.kind }}</span>
      </div>

      <div class="grid gap-5 sm:grid-cols-2">
        <Field label="Nama" :error="target.errors.name">
          <input v-model="target.name" required class="input" :placeholder="target.kind === 'pg_a' ? 'penampung-utama' : 'db-aplikasi-gudang'">
        </Field>
        <Field v-if="target.kind === 'pg_b'" label="Nama aplikasi" hint="Huruf kecil tanpa spasi, mis. gudang." :error="target.errors.app_name">
          <input v-model="target.app_name" required class="input mono" placeholder="gudang">
        </Field>

        <Field label="Alamat server (host)" :error="target.errors.host">
          <input v-model="target.host" required class="input mono" placeholder="localhost">
        </Field>
        <Field label="Port" hint="Biasanya 5432." :error="target.errors.port">
          <input v-model.number="target.port" type="number" required class="input">
        </Field>
        <Field label="Nama database" :error="target.errors.database">
          <input v-model="target.database" required class="input mono">
        </Field>
        <Field label="Username" hint="Akun yang boleh membuat & mengisi tabel." :error="target.errors.username">
          <input v-model="target.username" required class="input mono" autocomplete="off">
        </Field>
        <Field label="Password" :hint="editing ? 'Kosongkan jika tidak ingin mengubah password.' : 'Disimpan terenkripsi.'" :error="target.errors.password" class="sm:col-span-2">
          <input v-model="target.password" type="password" :required="!editing" class="input" autocomplete="new-password" :placeholder="editing ? '••••••••  (tidak diubah)' : ''">
        </Field>
      </div>

      <div v-if="target.kind === 'pg_b'" class="space-y-4 rounded-2xl border border-ink-200 bg-ink-50 p-5">
        <div>
          <p class="font-semibold text-ink-900"><Term term="readonly" /> untuk aplikasi <span class="text-sm font-normal text-ink-500">(disarankan)</span></p>
          <p class="mt-1 text-sm text-ink-600">Akun ini yang dipakai aplikasi Anda untuk membaca data.</p>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
          <Field label="Username baca-saja" :error="target.errors.readonly_username">
            <input v-model="target.readonly_username" class="input mono" placeholder="gudang_ro">
          </Field>
          <Field label="Password baca-saja" :hint="editing ? 'Kosongkan jika tidak diubah.' : ''" :error="target.errors.readonly_password">
            <input v-model="target.readonly_password" type="password" class="input" autocomplete="new-password">
          </Field>
        </div>
        <label class="flex items-start gap-3 text-sm text-ink-700">
          <input v-model="target.auto_provision_readonly" type="checkbox" class="checkbox mt-0.5">
          <span>Buatkan akun baca-saja ini secara otomatis di database tersebut (butuh akun di atas punya hak membuat user).</span>
        </label>
      </div>

      <WarningList
        v-if="extraErrors(target, ['kind', 'name', 'app_name', 'host', 'port', 'database', 'username', 'password', 'readonly_username', 'readonly_password']).length"
        tone="red"
        title="Belum bisa disimpan"
        :warnings="extraErrors(target, ['kind', 'name', 'app_name', 'host', 'port', 'database', 'username', 'password', 'readonly_username', 'readonly_password'])"
      />
    </form>
    <template #footer>
      <button type="button" class="btn-secondary" @click="closeModal">Batal</button>
      <button type="submit" form="target-form" class="btn-primary" :disabled="target.processing">
        {{ target.processing ? 'Menyimpan…' : editing ? 'Simpan perubahan' : 'Tambah database' }}
      </button>
    </template>
  </Modal>

  <!-- ============ Modal: kunci akses ============ -->
  <Modal :open="modal === 'token'" title="Buat kunci akses API" description="Kunci hanya berlaku untuk satu dataset dan hanya bisa memicu pengambilan data." @close="closeModal">
    <form id="token-form" class="space-y-5" @submit.prevent="issueToken">
      <Field label="Dataset yang boleh dipicu" :error="token.errors.dataset_id">
        <select v-model="token.dataset_id" required class="input">
          <option :value="null" disabled>Pilih dataset…</option>
          <option v-for="item in tokenableDatasets" :key="item.id" :value="item.id">{{ item.name }} — {{ item.target }}</option>
        </select>
      </Field>
      <div class="grid gap-5 sm:grid-cols-2">
        <Field label="Nama aplikasi" hint="Huruf kecil, angka, tanda hubung." :error="token.errors.app_name">
          <input v-model="token.app_name" required class="input mono" placeholder="gudang">
        </Field>
        <Field label="Label kunci" hint="mis. server-produksi, laptop-budi." :error="token.errors.name">
          <input v-model="token.name" required class="input" placeholder="server-produksi">
        </Field>
        <Field label="Maksimal permintaan per menit" :error="token.errors.rate_limit_per_minute">
          <input v-model.number="token.rate_limit_per_minute" type="number" min="1" max="600" class="input">
        </Field>
        <Field label="Hanya dari alamat IP" optional hint="Pisahkan dengan koma. Kosongkan untuk semua IP." :error="token.errors.allowed_ips">
          <input v-model="token.allowed_ips" class="input mono" placeholder="10.0.0.5, 10.0.0.6">
        </Field>
      </div>
    </form>
    <template #footer>
      <button type="button" class="btn-secondary" @click="closeModal">Batal</button>
      <button type="submit" form="token-form" class="btn-primary" :disabled="token.processing">
        {{ token.processing ? 'Membuat…' : 'Buat kunci' }}
      </button>
    </template>
  </Modal>
</template>
