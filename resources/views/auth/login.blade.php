<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — Oracle Data Sync</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-100">
    <div class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <!-- Kartu -->
            <div class="surface p-7">
                <div class="flex items-center gap-2.5">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 ring-4 ring-emerald-500/15"></span>
                    <span class="text-[15px] font-semibold tracking-tight text-ink-900">Oracle Data Sync</span>
                </div>

                <p class="mt-2 text-sm leading-relaxed text-ink-500">
                    Alat internal untuk menyalin data Oracle ke PostgreSQL.
                    Oracle hanya dibaca, tidak pernah ditulis.
                </p>

                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username" placeholder="nama@perusahaan.co.id"
                               class="input mt-1.5">
                    </div>

                    <div>
                        <label for="password" class="label">Password</label>
                        <input id="password" name="password" type="password" required
                               autocomplete="current-password" placeholder="••••••••"
                               class="input mt-1.5">
                    </div>

                    <label class="flex items-center gap-2 text-sm text-ink-600">
                        <input type="checkbox" name="remember" value="1"
                               class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                        Ingat saya
                    </label>

                    <button type="submit" class="btn-primary w-full">Masuk</button>
                </form>
            </div>

            <p class="mt-5 text-center text-xs leading-relaxed text-ink-400">
                Tidak ada pendaftaran mandiri. Akun dibuat admin lewat
                <code class="rounded bg-ink-100 px-1 py-0.5 font-mono text-[11px] text-ink-600">php artisan tinker</code>.
            </p>
        </div>
    </div>
</body>
</html>
