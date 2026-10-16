<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import PauseButton from '../Components/PauseButton.vue'

const page = usePage()

const paused = computed(() => Boolean(page.props.pause?.paused))
const isAdmin = computed(() => page.props.auth?.is_admin === true)
const userName = computed(() => page.props.auth?.name ?? '')
const userRole = computed(() => page.props.auth?.is_admin ? 'Admin' : 'Viewer')

const mobileOpen = ref(false)
watch(() => page.url, () => { mobileOpen.value = false })

const links = computed(() => {
  const items = [
    { href: '/', label: 'Dashboard', icon: 'M3 12l9-9 9 9M5 10v10h14V10' },
    { href: '/datasets', label: 'Dataset', icon: 'M4 6h16M4 12h16M4 18h10' },
    { href: '/copy', label: 'Copy A → B', icon: 'M8 7h11m0 0l-3-3m3 3l-3 3M16 17H5m0 0l3 3m-3-3l3-3' },
    { href: '/history', label: 'Riwayat', icon: 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z' },
    { href: '/pause', label: 'Pause', icon: 'M10 9v6m4-6v6' },
  ]

  if (isAdmin.value) {
    items.push({ href: '/drivers', label: 'Driver', icon: 'M4 6h16M4 12h16M4 18h16' })
  }

  return items
})

const isActive = (href) =>
  href === '/' ? page.url === '/' : page.url.startsWith(href)

function logout() {
  router.post('/logout')
}
</script>

<template>
  <div class="min-h-screen bg-ink-100">
    <!-- Kill switch aktif: pita merah di semua halaman -->
    <div v-if="paused" class="bg-red-600 px-4 py-2 text-center text-[13px] font-medium text-white">
      Semua job sedang di-PAUSE — tidak ada penulisan ke PostgreSQL A atau B.
    </div>

    <div class="flex">
      <!-- Sidebar (desktop) -->
      <aside class="hidden w-60 shrink-0 flex-col border-r border-ink-200 bg-white lg:flex">
        <div class="flex h-16 items-center gap-2.5 border-b border-ink-200 px-5">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 ring-4 ring-emerald-500/15"></span>
          <span class="text-[15px] font-semibold tracking-tight text-ink-900">Oracle Data Sync</span>
        </div>

        <nav class="flex-1 space-y-1 p-3">
          <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
            :class="isActive(link.href)
              ? 'bg-ink-900 text-white'
              : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'"
          >
            <svg class="h-[18px] w-[18px] shrink-0" :class="isActive(link.href) ? 'text-white' : 'text-ink-400 group-hover:text-ink-600'"
                 fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
              <path :d="link.icon" />
            </svg>
            {{ link.label }}
          </Link>
        </nav>

        <div class="space-y-3 border-t border-ink-200 p-4">
          <PauseButton :paused="paused" full />
        </div>
      </aside>

      <!-- Konten -->
      <div class="flex min-w-0 flex-1 flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-20 border-b border-ink-200 bg-white/85 backdrop-blur">
          <div class="flex h-16 items-center gap-4 px-4 lg:px-8">
            <button
              type="button"
              class="btn-ghost btn-sm lg:hidden"
              @click="mobileOpen = !mobileOpen"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
              Menu
            </button>

            <span class="text-[15px] font-semibold tracking-tight text-ink-900 lg:hidden">
              Oracle Data Sync
            </span>

            <div class="ml-auto flex items-center gap-3">
              <div class="hidden text-right sm:block">
                <p class="text-[13px] font-medium leading-tight text-ink-800">{{ userName }}</p>
                <p class="text-[11px] leading-tight text-ink-400">{{ userRole }}</p>
              </div>
              <button type="button" class="btn-secondary btn-sm" @click="logout">Keluar</button>
            </div>
          </div>

          <!-- Nav mobile -->
          <nav v-if="mobileOpen" class="space-y-1 border-t border-ink-200 p-3 lg:hidden">
            <Link
              v-for="link in links"
              :key="link.href"
              :href="link.href"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium"
              :class="isActive(link.href) ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-100'"
            >
              <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path :d="link.icon" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              {{ link.label }}
            </Link>
          </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-7 lg:px-8">
          <!-- Flash -->
          <div
            v-if="page.props.flash?.success"
            class="mb-5 flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
          >
            <span class="mt-px">✓</span>
            {{ page.props.flash.success }}
          </div>
          <div
            v-if="page.props.flash?.error"
            class="mb-5 flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
          >
            <span class="mt-px">!</span>
            {{ page.props.flash.error }}
          </div>

          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
