<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import PauseButton from '../Components/PauseButton.vue'

const page = usePage()

const paused = computed(() => Boolean(page.props.pause?.paused))
const isAdmin = computed(() => page.props.auth?.is_admin === true)
const userName = computed(() => page.props.auth?.name ?? '')

const mobileOpen = ref(false)
watch(() => page.url, () => { mobileOpen.value = false })

const links = computed(() => [
  { href: '/', label: 'Dashboard' },
  { href: '/datasets', label: 'Dataset' },
  { href: '/copy', label: 'Copy A → B' },
  { href: '/history', label: 'Riwayat' },
  { href: '/pause', label: 'Pause' },
  ...(isAdmin.value ? [{ href: '/drivers', label: 'Driver' }] : []),
])

const isActive = (href) =>
  href === '/' ? page.url === '/' : page.url.startsWith(href)

function logout() {
  router.post('/logout')
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900">
    <!-- Kill switch aktif: pita merah di semua halaman -->
    <div v-if="paused" class="bg-red-600 px-4 py-2 text-center text-sm font-medium text-white">
      Semua job sedang di-PAUSE. Tidak ada penulisan ke PostgreSQL A atau B.
    </div>

    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3">
        <Link href="/" class="flex items-center gap-2 font-semibold tracking-tight">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500" />
          Oracle&nbsp;Data&nbsp;Sync
        </Link>

        <nav class="ml-4 hidden items-center gap-1 md:flex">
          <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
            :class="isActive(link.href)
              ? 'bg-slate-900 text-white'
              : 'text-slate-600 hover:bg-slate-100'"
          >
            {{ link.label }}
          </Link>
        </nav>

        <div class="ml-auto flex items-center gap-3">
          <PauseButton :paused="paused" compact />
          <span class="hidden text-sm text-slate-600 sm:inline">{{ userName }}</span>
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
            @click="logout"
          >
            Keluar
          </button>
          <button
            type="button"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm md:hidden"
            @click="mobileOpen = !mobileOpen"
          >
            Menu
          </button>
        </div>
      </div>

      <nav v-if="mobileOpen" class="border-t border-slate-200 px-4 py-2 md:hidden">
        <Link
          v-for="link in links"
          :key="link.href"
          :href="link.href"
          class="block rounded-lg px-3 py-2 text-sm"
          :class="isActive(link.href) ? 'bg-slate-900 text-white' : 'text-slate-700'"
        >
          {{ link.label }}
        </Link>
      </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">
      <!-- Flash -->
      <div
        v-if="page.props.flash?.success"
        class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
      >
        {{ page.props.flash.success }}
      </div>
      <div
        v-if="page.props.flash?.error"
        class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
      >
        {{ page.props.flash.error }}
      </div>

      <slot />
    </main>
  </div>
</template>
