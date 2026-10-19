<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — Oracle Data Sync</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50">
    <div class="grid min-h-screen lg:grid-cols-2">
        <!-- Penjelasan singkat (layar lebar) -->
        <div class="hidden flex-col justify-between bg-brand-700 p-12 text-white lg:flex">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5.5h5v5H5zM14 13.5h5v5h-5zM7.5 10.5v5.5h6.5" /></svg>
                </span>
                <span class="text-lg font-bold tracking-tight">Oracle Data Sync</span>
            </div>

            <div class="max-w-md">
                <h1 class="text-3xl leading-tight font-bold">Salin data Oracle ke PostgreSQL, tanpa pernah mengubah Oracle.</h1>
                <ul class="mt-8 space-y-4 text-brand-100">
                    <li class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-300"></span>
                        Tulis query SELECT biasa, lihat contoh hasilnya, lalu simpan.
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-300"></span>
                        Data diperbarui otomatis sesuai jadwal yang Anda pilih.
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-300"></span>
                        Setiap pemindahan diperiksa agar hasilnya identik dengan sumber.
                    </li>
                </ul>
            </div>

            <p class="text-sm text-brand-200">Oracle hanya dibaca, tidak pernah ditulis.</p>
        </div>

        <!-- Form masuk -->
        <div class="flex items-center justify-center px-4 py-12 sm:px-8">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-white">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5.5h5v5H5zM14 13.5h5v5h-5zM7.5 10.5v5.5h6.5" /></svg>
                    </span>
                    <span class="text-lg font-bold tracking-tight text-ink-900">Oracle Data Sync</span>
                </div>

                <h2 class="text-2xl font-bold text-ink-900">Masuk</h2>
                <p class="mt-1.5 text-ink-500">Gunakan email dan password akun Anda.</p>

                @if ($errors->any())
                    <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username" placeholder="nama@contoh.com"
                               class="input mt-2">
                    </div>

                    <div>
                        <label for="password" class="label">Password</label>
                        <input id="password" name="password" type="password" required
                               autocomplete="current-password"
                               class="input mt-2">
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-ink-700">
                        <input type="checkbox" name="remember" value="1" class="checkbox">
                        Ingat saya di perangkat ini
                    </label>

                    <button type="submit" class="btn-primary w-full py-3">Masuk</button>
                </form>

                <p class="mt-8 text-center text-sm text-ink-500">
                    Belum punya akun? Minta admin tool ini untuk membuatkannya.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
