import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from './Layouts/AppLayout.vue'

createInertiaApp({
  title: (title) => (title ? `${title} — Oracle Data Sync` : 'Oracle Data Sync'),

  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
    const page = pages[`./Pages/${name}.vue`]

    if (!page) {
      throw new Error(`Halaman tidak ditemukan: ${name}`)
    }

    // Halaman tanpa layout (login) memakai layout milik PageLayout.
    if (name === 'Login') {
      return page
    }

    return { ...page, default: AppLayout }
  },

  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },

  progress: {
    color: '#0f172a',
  },
})
