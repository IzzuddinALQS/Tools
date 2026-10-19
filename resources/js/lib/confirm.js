import { reactive } from 'vue'

/*
 * Dialog konfirmasi global, pengganti window.confirm().
 *
 *   if (await confirm({ title: 'Hapus?', tone: 'danger' })) { ... }
 *
 * Dengan `input`, hasilnya { ok, value } — dipakai misalnya untuk meminta
 * alasan saat menghentikan semua proses.
 */
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  details: [],
  confirmLabel: 'Lanjutkan',
  cancelLabel: 'Batal',
  tone: 'primary',
  input: null,
  value: '',
  resolve: null,
})

export function confirm(options) {
  return new Promise((resolve) => {
    Object.assign(confirmState, {
      title: '',
      message: '',
      details: [],
      confirmLabel: 'Lanjutkan',
      cancelLabel: 'Batal',
      tone: 'primary',
      input: null,
      value: '',
      ...options,
      open: true,
      resolve,
    })
  })
}

export function settleConfirm(ok) {
  const { resolve, input, value } = confirmState
  confirmState.open = false
  resolve?.(input ? { ok, value } : ok)
  confirmState.resolve = null
}
