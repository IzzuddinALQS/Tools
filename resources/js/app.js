import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from './Layouts/AppLayout.vue'

/*
 * Halaman login dirender Blade (resources/views/auth/login.blade.php), bukan
 * Inertia, jadi tidak punya elemen #app.
 *
 * createInertiaApp() akan melempar
 *   TypeError: Cannot read properties of null (reading 'component')
 * kalau dipanggil tanpa elemen tersebut. Karena itu boot hanya jalan bila
 * elemennya benar-benar ada.
 */
const root = document.getElementById('app')

if (root) {
  createInertiaApp({
    title: (title) => (title ? `${title} — Oracle Data Sync` : 'Oracle Data Sync'),

    resolve: (name) => {
      const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
      const page = pages[`./Pages/${name}.vue`]

      if (!page) {
        throw new Error(`Halaman tidak ditemukan: ${name}`)
      }

      /*
       * Layout dipasang sebagai PROPERTI pada komponen halaman, bukan dengan
       * mengganti `default`.
       *
       * Cara salah: return { ...page, default: AppLayout }
       * Itu mengganti komponen halaman itu sendiri dengan layout, sehingga
       * <slot /> di layout tidak pernah menerima isi — header tampil tapi
       * seluruh konten halaman hilang.
       *
       * Cara benar (pola resmi Inertia): set `layout` pada komponen halaman,
       * lalu Inertia yang merender halaman di dalam layout dan meneruskan
       * isi halaman sebagai default slot.
       */
      page.default.layout = name === 'Login' ? undefined : AppLayout

      return page
    },

    setup({ el, App, props, plugin }) {
      createApp({ render: () => h(App, props) })
        .use(plugin)
        .mount(el)
    },

    progress: {
      color: '#1d3ab6',
    },
  })
}
