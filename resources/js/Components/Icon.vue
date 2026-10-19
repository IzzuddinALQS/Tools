<script setup>
/*
 * Ikon garis 24×24. Satu komponen supaya path SVG tidak tersebar di
 * setiap halaman dan gayanya konsisten.
 */
const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
})

const PATHS = {
  home: 'M3 11.5 12 4l9 7.5M5.5 10v9.5h13V10',
  database: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3Zm0 0v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
  table: 'M3.5 5.5h17v13h-17zM3.5 10h17M3.5 14.5h17M9 10v8.5',
  send: 'M4 12h13m0 0-4.5-4.5M17 12l-4.5 4.5M20 5v14',
  history: 'M3.5 12a8.5 8.5 0 1 0 2.5-6M3.5 4v4h4M12 8v4.5l3 2',
  pause: 'M9.5 8v8m5-8v8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
  play: 'M8 5.5v13l11-6.5-11-6.5Z',
  plug: 'M9 3v5m6-5v5M6.5 8h11v3a5.5 5.5 0 0 1-11 0V8Zm5.5 8.5V21',
  key: 'M14.5 9.5a4 4 0 1 1-1.2-2.8M14.5 9.5 21 3m-3 3 2 2m-4.5-1.5 2 2',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7.5 8.5c.8-3.5 3.8-5.5 7.5-5.5s6.7 2 7.5 5.5',
  logout: 'M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15M10 16.5 5.5 12 10 7.5M5.5 12H15',
  lock: 'M6.5 10.5h11v9h-11zM8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5',
  menu: 'M4 6.5h16M4 12h16M4 17.5h16',
  close: 'M6 6l12 12M18 6 6 18',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  'check-circle': 'M8.5 12.5l2.5 2.5 4.5-5M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
  alert: 'M12 9v4.5m0 3v.01M10.3 4.2 2.8 17.5A2 2 0 0 0 4.5 20.5h15a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z',
  info: 'M12 11v5.5m0-9v.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
  help: 'M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.4m0 3v.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4',
  trash: 'M4.5 7h15M10 11v6m4-6v6M6 7l1 12.5h10L18 7M9 7V4.5h6V7',
  refresh: 'M19.5 12a7.5 7.5 0 0 1-13.5 4.5M4.5 12A7.5 7.5 0 0 1 18 7.5M18 3.5v4h-4M6 20.5v-4h4',
  copy: 'M8.5 8.5h11v11h-11zM15.5 8.5v-4h-11v11h4',
  arrow: 'M5 12h14m0 0-5-5m5 5-5 5',
  'arrow-left': 'M19 12H5m0 0 5-5m-5 5 5 5',
  chevron: 'M9 6l6 6-6 6',
  'chevron-down': 'M6 9l6 6 6-6',
  clock: 'M12 7.5V12l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
  shield: 'M12 3 4.5 6v5.5c0 4.5 3.2 8.2 7.5 9.5 4.3-1.3 7.5-5 7.5-9.5V6L12 3Z',
  spark: 'M12 3v4m0 10v4M3 12h4m10 0h4M6 6l2.5 2.5m7 7L18 18M6 18l2.5-2.5m7-7L18 6',
  code: 'M8.5 7 3.5 12l5 5m7-10 5 5-5 5',
  search: 'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm4.6-1.9L20 20',
  flow:'M5 5.5h5v5H5zM14 13.5h5v5h-5zM7.5 10.5v5.5h6.5',
}
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    class="shrink-0"
  >
    <path :d="PATHS[props.name] ?? PATHS.info" />
  </svg>
</template>
