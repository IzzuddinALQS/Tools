<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Wajib untuk fetch() manual di halaman Dataset Editor. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/favicon.ico">
    <title inertia>{{ config('app.name', 'Oracle Data Sync') }}</title>
    {{-- Tema: dipasang sebelum CSS dimuat agar tidak berkedip. Pilihan
         disimpan di localStorage ('ods-theme'): light | dark | (kosong = ikut sistem). --}}
    <script>
        (function () {
            var pref = null;
            try { pref = localStorage.getItem('ods-theme'); } catch (e) {}
            var dark = pref ? pref === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
