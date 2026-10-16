import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: true,
    }),
    vue(),
    /*
     * WAJIB. Tanpa plugin ini Tailwind tidak pernah dijalankan: @apply jadi
     * CSS tidak valid yang diabaikan browser, dan nol utility class yang
     * dihasilkan sehingga halaman tampil tanpa style sama sekali.
     */
    tailwindcss(),
  ],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: false,
  },
})
