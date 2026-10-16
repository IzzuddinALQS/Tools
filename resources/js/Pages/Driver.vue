<script setup>
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import PageHeader from '../Components/PageHeader.vue'

const props = defineProps({
  connections: { type: Array, default: () => [] },
  targets: { type: Array, default: () => [] },
  defaults: { type: Object, required: true },
})

const testing = ref(null)

const connection = useForm({
  name: '', description: '', driver: props.defaults.driver,
  host: '', port: props.defaults.oracle_port,
  service_name: '', sid: '', tns_alias: '', dsn: '',
  username: '', password: '', fixture_set: 'armors', is_active: true,
})

const target = useForm({
  name: '', description: '', kind: 'pg_b', app_name: '',
  host: '', port: props.defaults.pg_port, database: '', username: '',
  password: '', sslmode: 'prefer',
  readonly_username: '', readonly_password: '', auto_provision_readonly: false,
  is_active: true,
})

function testConnection(id) {
  testing.value = `c-${id}`
  router.post(`/drivers/connections/${id}/test`, {}, {
    preserveScroll: true,
    onFinish: () => { testing.value = null },
  })
}

function testTarget(id) {
  testing.value = `t-${id}`
  router.post(`/drivers/targets/${id}/test`, {}, {
    preserveScroll: true,
    onFinish: () => { testing.value = null },
  })
}

function removeConnection(item) {
  if (window.confirm(`Hapus koneksi "${item.name}"?`)) {
    router.delete(`/drivers/connections/${item.id}`, { preserveScroll: true })
  }
}

function removeTarget(item) {
  if (window.confirm(`Hapus target "${item.name}"?`)) {
    router.delete(`/drivers/targets/${item.id}`, { preserveScroll: true })
  }
}

const errorsOf = (form) => Object.entries(form.errors).flatMap(([key, messages]) =>
  Array.isArray(messages) ? messages.map((m) => `${key}: ${m}`) : [`${key}: ${messages}`],
)
</script>

<template>
  <Head title="Driver" />

  <PageHeader title="Driver & Koneksi">
    <template #subtitle>
      Halaman ini hanya untuk admin. Password disimpan terenkripsi, tidak pernah ditampilkan lagi, dan tidak pernah di-log.
    </template>

    <div class="flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm">
      <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-200 text-[11px] font-bold text-red-800">!</span>
      <p class="leading-relaxed text-red-800">
        <strong>Pengaman utama ada di Oracle, bukan di sini.</strong>
        User Oracle wajib dibuat DBA dengan hak SELECT saja. Tool ini hanya membaca satu statement
        SELECT per dataset dan tidak pernah menulis ke Oracle.
      </p>
    </div>

    <!-- ================= Koneksi sumber ================= -->
    <section class="space-y-3">
      <h2 class="eyebrow">Koneksi sumber</h2>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Driver</th>
              <th>Host / tujuan</th>
              <th>User</th>
              <th>Tes terakhir</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="connections.length === 0">
              <td colspan="6" class="py-8 text-center text-ink-400">Belum ada koneksi sumber.</td>
            </tr>
            <tr v-for="item in connections" :key="item.id">
              <td class="font-medium text-ink-900">{{ item.name }}</td>
              <td>
                <span :class="item.driver === 'oracle' ? 'badge-info' : 'badge-neutral'">{{ item.driver }}</span>
              </td>
              <td class="mono text-xs text-ink-600">
                <template v-if="item.driver === 'oracle'">
                  {{ item.host }}:{{ item.port }} / {{ item.service_name || item.sid || item.tns_alias }}
                </template>
                <template v-else>fixture: {{ item.fixture_set || 'armors' }}</template>
              </td>
              <td class="mono text-xs text-ink-600">{{ item.username || '—' }}</td>
              <td class="text-xs">
                <span v-if="item.last_test_status === 'ok'" class="text-emerald-700">OK</span>
                <span v-else-if="item.last_test_status" class="text-red-700">Gagal</span>
                <span v-else class="text-ink-400">belum dites</span>
                <span v-if="item.last_tested_at" class="block text-ink-400">{{ item.last_tested_at }}</span>
              </td>
              <td class="text-right whitespace-nowrap">
                <button type="button" class="btn-secondary btn-xs"
                        :disabled="testing === `c-${item.id}`"
                        @click="testConnection(item.id)">
                  {{ testing === `c-${item.id}` ? '…' : 'Tes Koneksi' }}
                </button>
                <button type="button" class="btn-danger btn-xs ml-1" @click="removeConnection(item)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="surface p-5" @submit.prevent="connection.post('/drivers/connections')">
        <h3 class="text-sm font-semibold text-ink-900">Tambah koneksi sumber</h3>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="block">
            <span class="eyebrow">Nama</span>
            <input v-model="connection.name" required class="input-sm mt-1.5" placeholder="mock-armors">
          </label>
          <label class="block">
            <span class="eyebrow">Driver</span>
            <select v-model="connection.driver" class="input-sm mt-1.5">
              <option v-for="driver in defaults.drivers" :key="driver.value" :value="driver.value">{{ driver.label }}</option>
            </select>
          </label>

          <template v-if="connection.driver === 'oracle'">
            <label class="block">
              <span class="eyebrow">Host</span>
              <input v-model="connection.host" required class="input-sm mono mt-1.5" placeholder="oracle.example">
            </label>
            <label class="block">
              <span class="eyebrow">Port</span>
              <input v-model.number="connection.port" type="number" class="input-sm mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">Service name</span>
              <input v-model="connection.service_name" class="input-sm mono mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">SID (alternatif)</span>
              <input v-model="connection.sid" class="input-sm mono mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">TNS alias (alternatif)</span>
              <input v-model="connection.tns_alias" class="input-sm mono mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">Username</span>
              <input v-model="connection.username" class="input-sm mono mt-1.5">
            </label>
            <label class="block">
              <span class="eyebrow">Password</span>
              <input v-model="connection.password" type="password" required class="input-sm mt-1.5" autocomplete="new-password">
            </label>
          </template>

          <template v-else>
            <label class="block">
              <span class="eyebrow">Katalog fixture</span>
              <input v-model="connection.fixture_set" class="input-sm mono mt-1.5" placeholder="armors">
            </label>
            <label class="block">
              <span class="eyebrow">Username (opsional)</span>
              <input v-model="connection.username" class="input-sm mono mt-1.5">
            </label>
          </template>
        </div>

        <ul v-if="errorsOf(connection).length" class="mt-3 space-y-0.5 text-xs text-red-600">
          <li v-for="message in errorsOf(connection)" :key="message">{{ message }}</li>
        </ul>

        <div class="mt-4 flex items-center gap-3 border-t border-ink-100 pt-4">
          <button type="submit" class="btn-primary" :disabled="connection.processing">Tambah koneksi</button>
          <span v-if="connection.driver === 'oracle'" class="text-xs text-ink-400">
            Password disimpan terenkripsi dan tidak pernah ditampilkan lagi.
          </span>
        </div>
      </form>
    </section>

    <!-- ================= Target PostgreSQL ================= -->
    <section class="space-y-3">
      <h2 class="eyebrow">Target PostgreSQL</h2>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Peran</th>
              <th>Database</th>
              <th>User aplikasi</th>
              <th>Tes terakhir</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="targets.length === 0">
              <td colspan="6" class="py-8 text-center text-ink-400">Belum ada target.</td>
            </tr>
            <tr v-for="item in targets" :key="item.id">
              <td class="font-medium text-ink-900">{{ item.name }}</td>
              <td>
                <span :class="item.kind === 'pg_a' ? 'badge-success' : 'badge-info'">{{ item.kind_label }}</span>
              </td>
              <td class="mono text-xs text-ink-600">{{ item.host }}:{{ item.port }} / {{ item.database }}</td>
              <td class="mono text-xs text-ink-600">{{ item.readonly_username || '—' }}</td>
              <td class="text-xs">
                <span v-if="item.last_test_status === 'ok'" class="text-emerald-700">OK</span>
                <span v-else-if="item.last_test_status" class="text-red-700">Gagal</span>
                <span v-else class="text-ink-400">belum dites</span>
              </td>
              <td class="text-right whitespace-nowrap">
                <button type="button" class="btn-secondary btn-xs"
                        :disabled="testing === `t-${item.id}`"
                        @click="testTarget(item.id)">
                  {{ testing === `t-${item.id}` ? '…' : 'Tes Koneksi' }}
                </button>
                <button type="button" class="btn-danger btn-xs ml-1" @click="removeTarget(item)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="surface p-5" @submit.prevent="target.post('/drivers/targets')">
        <h3 class="text-sm font-semibold text-ink-900">Tambah target PostgreSQL</h3>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="block">
            <span class="eyebrow">Nama</span>
            <input v-model="target.name" required class="input-sm mt-1.5" placeholder="PG B armors">
          </label>
          <label class="block">
            <span class="eyebrow">Peran</span>
            <select v-model="target.kind" class="input-sm mt-1.5">
              <option v-for="kind in defaults.target_kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
            </select>
          </label>
          <label v-if="target.kind === 'pg_b'" class="block">
            <span class="eyebrow">Nama aplikasi</span>
            <input v-model="target.app_name" required class="input-sm mono mt-1.5" placeholder="armors">
          </label>
          <label class="block">
            <span class="eyebrow">Host</span>
            <input v-model="target.host" required class="input-sm mono mt-1.5">
          </label>
          <label class="block">
            <span class="eyebrow">Port</span>
            <input v-model.number="target.port" type="number" class="input-sm mt-1.5">
          </label>
          <label class="block">
            <span class="eyebrow">Database</span>
            <input v-model="target.database" required class="input-sm mono mt-1.5">
          </label>
          <label class="block">
            <span class="eyebrow">Username</span>
            <input v-model="target.username" required class="input-sm mono mt-1.5">
          </label>
          <label class="block">
            <span class="eyebrow">Password</span>
            <input v-model="target.password" type="password" required class="input-sm mt-1.5" autocomplete="new-password">
          </label>

          <template v-if="target.kind === 'pg_b'">
            <label class="block">
              <span class="eyebrow">User read-only</span>
              <input v-model="target.readonly_username" class="input-sm mono mt-1.5" placeholder="armors_ro">
            </label>
            <label class="block">
              <span class="eyebrow">Password read-only</span>
              <input v-model="target.readonly_password" type="password" class="input-sm mt-1.5" autocomplete="new-password">
            </label>
          </template>
        </div>

        <ul v-if="errorsOf(target).length" class="mt-3 space-y-0.5 text-xs text-red-600">
          <li v-for="message in errorsOf(target)" :key="message">{{ message }}</li>
        </ul>

        <div class="mt-4 flex items-center gap-3 border-t border-ink-100 pt-4">
          <button type="submit" class="btn-primary" :disabled="target.processing">Tambah target</button>
          <span class="text-xs text-ink-400">Hanya boleh ada satu PostgreSQL A.</span>
        </div>
      </form>
    </section>
  </PageHeader>
</template>
