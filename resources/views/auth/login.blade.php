<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — Oracle Data Sync</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 space-y-5">
            <div>
                <h1 class="text-lg font-semibold text-slate-900">Oracle Data Sync</h1>
                <p class="text-sm text-slate-500 mt-1">Alat internal untuk menyalin data Oracle ke PostgreSQL.</p>
            </div>

            @if ($errors->any())
                <div class="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           autocomplete="username"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900">
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
                    Ingat saya
                </label>

                <button type="submit"
                        class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Masuk
                </button>
            </form>
        </div>

        <p class="mt-4 text-xs text-slate-500 text-center">
            Akun dibuat oleh admin lewat <code>php artisan</code>. Tidak ada pendaftaran mandiri.
        </p>
    </div>
</body>
</html>
