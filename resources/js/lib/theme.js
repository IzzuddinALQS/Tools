import { ref } from 'vue'

/*
 * Pilihan tema: 'system' | 'light' | 'dark'. Atribut data-theme awal sudah
 * dipasang skrip di app.blade.php; modul ini hanya untuk mengubahnya.
 */
const KEY = 'ods-theme'
const media = window.matchMedia('(prefers-color-scheme: dark)')

function read() {
  try {
    return localStorage.getItem(KEY) || 'system'
  } catch {
    return 'system'
  }
}

export const themePreference = ref(read())

function apply() {
  const dark = themePreference.value === 'system' ? media.matches : themePreference.value === 'dark'
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light')
}

media.addEventListener?.('change', () => {
  if (themePreference.value === 'system') apply()
})

export function setTheme(value) {
  themePreference.value = value
  try {
    if (value === 'system') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, value)
  } catch {
    // Penyimpanan diblokir: tema tetap berlaku untuk tab ini saja.
  }
  apply()
}
