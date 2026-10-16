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
  testing.value = `connection-${id}`
  router.post(`/drivers/connections/${id}/test`, {}, {
    preserveScroll: true,
    onFinish: () => { testing.value = null },
  })
}

function testTarget(id) {
  testing.value = `target-${id}`
  router.post(`/drivers/targets/${id}/test`, {}, {
    preserveScroll: true,
    onFinish: () => { testing.value = null },
  })
}

function saveConnection() {
  connection.post('/drivers/connections')
}

function saveTarget() {
  target.post('/drivers/targets')
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
</script>

<template>
  <Head title="Driver" />

  <PageHeader title="Driver & Koneksi">
    <template #subtitle>
      <p class="mt-1 text-sm text-slate-500">
        Halaman ini hanya untuk admin. Password disimpan terenkripsi, tidak pernah ditampilkan lagi,
        dan tidak pernah di-log.
      </p>
    </template>

    <div
      class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
    >
      <strong>Pengaman utama ada di Oracle, bukan di sini.</strong>
      User Oracle wajib dibuat DBA dengan hak SELECT saja. Tool ini hanya membaca
      satu statement SELECT per dataset dan tidak pernah menulis ke Oracle.
    </div>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Koneksi sumber</h2>

      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Nama</th>
              <th class="px-3 py-2">Driver</th>
              <th class="px-3 py-2">Host / tujuan</th>
              <th class="px-3 py-2">User</th>
              <th class="px-3 py-2">Tes terakhir</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="connections.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-slate-500">Belum ada koneksi sumber.</td>
            </tr>
            <tr v-for="item in connections" :key="item.id" class="border-t border-slate-100">
              <td class="px-3 py-2 font-medium">{{ item.name }}</td>
              <td class="px-3 py-2">
                <span class="rounded-full px-2 py-0.5 text-xs"
                      :class="item.driver === 'oracle' ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-700'">
                  {{ item.driver }}
                </span>
              </td>
              <td class="px-3 py-2 text-xs text-slate-600">
                <span v-if="item.driver === 'oracle'">{{ item.host }}:{{ item.port }} / {{ item.service_name || item.sid || item.tns_alias }}</span>
                <span v-else>fixture: {{ item.fixture_set || 'armors' }}</span>
              </td>
              <td class="px-3 py-2 text-xs text-slate-600">{{ item.username || '—' }}</td>
              <td class="px-3 py-2 text-xs">
                <span :class="item.last_test_status === 'ok' ? 'text-emerald-700' : item.last_test_status ? 'text-red-700' : 'text-slate-400'">
                  {{ item.last_test_status || 'belum dites' }}
                </span>
                <span class="block text-slate-400">{{ item.last_tested_at || '' }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
                        :disabled="testing === `connection-${item.id}`"
                        @click="testConnection(item.id)">
                  {{ testing === `connection-${item.id}` ? '…' : 'Tes Koneksi' }}
                </button>
                <button type="button" class="ml-1 rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50"
                        @click="removeConnection(item)">
                  Hapus
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
            @submit.prevent="saveConnection">
        <label class="block">
          <span class="text-xs text-slate-600">Nama</span>
          <input v-model="connection.name" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Driver</span>
          <select v-model="connection.driver" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <option v-for="driver in defaults.drivers" :key="driver.value" :value="driver.value">{{ driver.label }}</option>
          </select>
        </label>

        <template v-if="connection.driver === 'oracle'">
          <label class="block">
            <span class="text-xs text-slate-600">Host</span>
            <input v-model="connection.host" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Port</span>
            <input v-model.number="connection.port" type="number" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Service name</span>
            <input v-model="connection.service_name" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">SID (alternatif)</span>
            <input v-model="connection.sid" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">TNS alias (alternatif)</span>
            <input v-model="connection.tns_alias" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Username</span>
            <input v-model="connection.username" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Password</span>
            <input v-model="connection.password" type="password" required
                   class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
          </label>
        </template>

        <template v-else>
          <label class="block">
            <span class="text-xs text-slate-600">Katalog fixture</span>
            <input v-model="connection.fixture_set" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Username (opsional)</span>
            <input v-model="connection.username" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
        </template>

        <div class="flex items-end">
          <button type="submit" class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                  :disabled="connection.processing">
            Tambah koneksi
          </button>
        </div>

        <ul v-if="Object.keys(connection.errors).length" class="sm:col-span-2 lg:col-span-4">
          <li v-for="(messages, key) in connection.errors" :key="key" class="text-xs text-red-600">{{ messages }}</li>
        </ul>
      </form>
    </section>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Target PostgreSQL</h2>

      <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Nama</th>
              <th class="px-3 py-2">Peran</th>
              <th class="px-3 py-2">Database</th>
              <th class="px-3 py-2">User aplikasi</th>
              <th class="px-3 py-2">Tes terakhir</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="targets.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-slate-500">Belum ada target.</td>
            </tr>
            <tr v-for="item in targets" :key="item.id" class="border-t border-slate-100">
              <td class="px-3 py-2 font-medium">{{ item.name }}</td>
              <td class="px-3 py-2">
                <span class="rounded-full px-2 py-0.5 text-xs"
                      :class="item.kind === 'pg_a' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800'">
                  {{ item.kind_label }}
                </span>
              </td>
              <td class="px-3 py-2 text-xs text-slate-600">{{ item.host }}:{{ item.port }} / {{ item.database }}</td>
              <td class="px-3 py-2 text-xs text-slate-600">{{ item.readonly_username || '—' }}</td>
              <td class="px-3 py-2 text-xs">
                <span :class="item.last_test_status === 'ok' ? 'text-emerald-700' : item.last_test_status ? 'text-red-700' : 'text-slate-400'">
                  {{ item.last_test_status || 'belum dites' }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
                        :disabled="testing === `target-${item.id}`"
                        @click="testTarget(item.id)">
                  {{ testing === `target-${item.id}` ? '…' : 'Tes Koneksi' }}
                </button>
                <button type="button" class="ml-1 rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50"
                        @click="removeTarget(item)">
                  Hapus
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
            @submit.prevent="saveTarget">
        <label class="block">
          <span class="text-xs text-slate-600">Nama</span>
          <input v-model="target.name" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Peran</span>
          <select v-model="target.kind" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            <option v-for="kind in defaults.target_kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
          </select>
        </label>
        <label v-if="target.kind === 'pg_b'" class="block">
          <span class="text-xs text-slate-600">Nama aplikasi</span>
          <input v-model="target.app_name" required placeholder="armors"
                 class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Host</span>
          <input v-model="target.host" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Port</span>
          <input v-model.number="target.port" type="number" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Database</span>
          <input v-model="target.database" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Username</span>
          <input v-model="target.username" required class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
        </label>
        <label class="block">
          <span class="text-xs text-slate-600">Password</span>
          <input v-model="target.password" type="password" required
                 class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
        </label>

        <template v-if="target.kind === 'pg_b'">
          <label class="block">
            <span class="text-xs text-slate-600">User read-only (opsional)</span>
            <input v-model="target.readonly_username" placeholder="armors_ro"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs">
          </label>
          <label class="block">
            <span class="text-xs text-slate-600">Password user read-only</span>
            <input v-model="target.readonly_password" type="password"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
          </label>
        </template>

        <div class="flex items-end">
          <button type="submit" class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                  :disabled="target.processing">
            Tambah target
          </button>
        </div>

        <ul v-if="Object.keys(target.errors).length" class="sm:col-span-2 lg:col-span-4">
          <li v-for="(messages, key) in target.errors" :key="key" class="text-xs text-red-600">{{ messages }}</li>
        </ul>
      </form>
    </section>
  </PageHeader>
</template>
