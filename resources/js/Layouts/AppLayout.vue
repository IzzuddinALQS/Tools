<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import PauseButton from '../Components/PauseButton.vue'
import Icon from '../Components/Icon.vue'
import Modal from '../Components/Modal.vue'
import Field from '../Components/Field.vue'
import Toasts from '../Components/Toasts.vue'
import ConfirmDialog from '../Components/ConfirmDialog.vue'
import { setTheme, themePreference } from '../lib/theme.js'

const page = usePage()

const paused = computed(() => Boolean(page.props.pause?.paused))
const isAdmin = computed(() => page.props.auth?.is_admin === true)
const userName = computed(() => page.props.auth?.name ?? '')
const userEmail = computed(() => page.props.auth?.email ?? '')
const userRole = computed(() => (page.props.auth?.is_admin ? 'Admin' : 'Pengguna'))
const initials = computed(() => userName.value.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?')

const mobileOpen = ref(false)
const profileOpen = ref(false)
watch(() => page.url, () => {
  mobileOpen.value = false
  profileOpen.value = false
})

const links = computed(() => {
  return [
    { href: '/', label: 'Beranda', icon: 'home' },
    // Koneksi hanya untuk admin; urutannya mengikuti alur kerja.
    ...(isAdmin.value ? [{ href: '/drivers', label: 'Koneksi', icon: 'plug' }] : []),
    { href: '/datasets', label: 'Dataset', icon: 'table' },
    { href: '/copy', label: 'Kirim ke Aplikasi', icon: 'send' },
    { href: '/history', label: 'Riwayat', icon: 'history' },
    { href: '/bantuan', label: 'Bantuan', icon: 'help' },
  ]
})

const isActive = (href) =>
  href === '/' ? page.url === '/' : page.url.startsWith(href)

function logout() {
  router.post('/logout')
}

const passwordOpen = ref(false)
const password = useForm({
  current_password: '', password: '', password_confirmation: '',
})

function openPassword() {
  profileOpen.value = false
  passwordOpen.value = true
}

function changePassword() {
  password.post('/profile/password', {
    preserveScroll: true,
    onSuccess: () => {
      password.reset()
      passwordOpen.value = false
    },
  })
}
</script>

<template>
  <div class="min-h-screen bg-ink-50">
    <!-- Tombol darurat aktif: pita di semua halaman -->
    <div v-if="paused" class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-purple-600 px-4 py-2.5 text-center text-sm text-white">
      <span class="inline-flex items-center gap-2 font-medium">
        <Icon name="pause" :size="16" />
        Semua proses sedang dihentikan — tidak ada data yang ditulis.
      </span>
      <Link href="/pause" class="font-semibold underline underline-offset-2 hover:no-underline">Lihat detail</Link>
    </div>

    <div class="flex">
      <!-- Sidebar (desktop) -->
      <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-ink-200 bg-surface lg:flex">
        <Link href="/" class="flex h-[4.5rem] items-center gap-3 px-6">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm">
            <Icon name="flow" :size="20" />
          </span>
          <span class="leading-tight">
            <span class="block text-[15px] font-bold tracking-tight text-ink-900">Oracle Data Sync</span>
            <span class="block text-xs text-ink-500">Oracle → PostgreSQL</span>
          </span>
        </Link>

        <nav class="flex-1 space-y-1 px-3 py-4" aria-label="Menu utama">
          <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-[15px] font-medium transition"
            :class="isActive(link.href)
              ? 'bg-brand-50 text-brand-700'
              : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'"
            :aria-current="isActive(link.href) ? 'page' : undefined"
          >
            <span :class="isActive(link.href) ? 'text-brand-600' : 'text-ink-400 group-hover:text-ink-600'">
              <Icon :name="link.icon" :size="20" />
            </span>
            {{ link.label }}
          </Link>
        </nav>

        <div class="m-3 space-y-3 rounded-2xl border border-ink-200 bg-ink-50 p-4">
          <div class="flex items-center gap-2 text-sm">
            <span class="h-2 w-2 rounded-full" :class="paused ? 'bg-purple-500' : 'bg-emerald-500'"></span>
            <span class="font-medium text-ink-800">{{ paused ? 'Semua proses dihentikan' : 'Semua proses berjalan' }}</span>
          </div>
          <PauseButton :paused="paused" full />
          <Link href="/pause" class="block text-center text-xs font-medium text-ink-500 hover:text-ink-800">
            Apa fungsi tombol ini?
          </Link>
        </div>
      </aside>

      <!-- Konten -->
      <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 border-b border-ink-200 bg-surface/90 backdrop-blur">
          <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-10">
            <button
              type="button"
              class="btn-ghost btn-sm lg:hidden"
              :aria-expanded="mobileOpen"
              aria-label="Buka menu"
              @click="mobileOpen = !mobileOpen"
            >
              <Icon :name="mobileOpen ? 'close' : 'menu'" :size="20" />
            </button>

            <Link href="/" class="flex items-center gap-2 lg:hidden">
              <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white">
                <Icon name="flow" :size="18" />
              </span>
              <span class="text-[15px] font-bold tracking-tight text-ink-900">Oracle Data Sync</span>
            </Link>

            <!-- Menu profil -->
            <div class="relative ml-auto">
              <button
                type="button"
                class="flex items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-ink-100"
                :aria-expanded="profileOpen"
                @click="profileOpen = !profileOpen"
              >
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{{ initials }}</span>
                <span class="hidden text-left sm:block">
                  <span class="block text-sm leading-tight font-semibold text-ink-900">{{ userName }}</span>
                  <span class="block text-xs leading-tight text-ink-500">{{ userRole }}</span>
                </span>
                <span class="text-ink-400"><Icon name="chevron-down" :size="16" /></span>
              </button>

              <div v-if="profileOpen" class="fixed inset-0 z-40" @click="profileOpen = false"></div>
              <div
                v-if="profileOpen"
                class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-2xl border border-ink-200 bg-surface shadow-xl"
              >
                <div class="border-b border-ink-100 px-4 py-3">
                  <p class="truncate text-sm font-semibold text-ink-900">{{ userName }}</p>
                  <p class="truncate text-xs text-ink-500">{{ userEmail }}</p>
                </div>
                <div class="border-b border-ink-100 px-4 py-3">
                  <p class="text-xs font-medium text-ink-500">Tampilan</p>
                  <div class="mt-2 grid grid-cols-3 gap-1 rounded-xl bg-ink-100 p-1" role="radiogroup" aria-label="Tema tampilan">
                    <button
                      v-for="opt in [{ v: 'light', l: 'Terang' }, { v: 'dark', l: 'Gelap' }, { v: 'system', l: 'Otomatis' }]"
                      :key="opt.v"
                      type="button"
                      role="radio"
                      :aria-checked="themePreference === opt.v"
                      class="rounded-lg px-2 py-1 text-xs font-medium transition"
                      :class="themePreference === opt.v ? 'bg-surface text-ink-900 shadow-sm' : 'text-ink-600 hover:text-ink-900'"
                      @click="setTheme(opt.v)"
                    >
                      {{ opt.l }}
                    </button>
                  </div>
                </div>
                <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-ink-700 hover:bg-ink-50" @click="openPassword">
                  <Icon name="lock" /> Ganti password
                </button>
                <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-red-700 hover:bg-red-50" @click="logout">
                  <Icon name="logout" /> Keluar
                </button>
              </div>
            </div>
          </div>

          <!-- Nav mobile -->
          <nav v-if="mobileOpen" class="space-y-1 border-t border-ink-200 bg-surface p-3 lg:hidden" aria-label="Menu utama">
            <Link
              v-for="link in links"
              :key="link.href"
              :href="link.href"
              class="flex items-center gap-3 rounded-xl px-3.5 py-3 text-[15px] font-medium"
              :class="isActive(link.href) ? 'bg-brand-50 text-brand-700' : 'text-ink-700 hover:bg-ink-100'"
            >
              <Icon :name="link.icon" :size="20" />
              {{ link.label }}
            </Link>
            <div class="border-t border-ink-100 pt-3">
              <PauseButton :paused="paused" full />
            </div>
          </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 lg:px-10 lg:py-12">
          <slot />
        </main>
      </div>
    </div>

    <!-- Ganti password -->
    <Modal :open="passwordOpen" title="Ganti password" description="Sesi di perangkat lain otomatis keluar. Sesi di tab ini tetap aktif." size="sm" @close="passwordOpen = false">
      <form id="password-form" class="space-y-4" @submit.prevent="changePassword">
        <Field label="Password saat ini" :error="password.errors.current_password">
          <input v-model="password.current_password" type="password" required autocomplete="current-password" class="input">
        </Field>
        <Field label="Password baru" hint="Minimal 12 karakter." :error="password.errors.password">
          <input v-model="password.password" type="password" required minlength="12" autocomplete="new-password" class="input">
        </Field>
        <Field label="Ulangi password baru">
          <input v-model="password.password_confirmation" type="password" required autocomplete="new-password" class="input">
        </Field>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary" @click="passwordOpen = false">Batal</button>
        <button type="submit" form="password-form" class="btn-primary" :disabled="password.processing">
          {{ password.processing ? 'Menyimpan…' : 'Simpan password' }}
        </button>
      </template>
    </Modal>

    <Toasts />
    <ConfirmDialog />
  </div>
</template>
