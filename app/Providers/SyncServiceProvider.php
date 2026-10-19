<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Services\Api\ApiTokenLookup;
use App\Services\Guard\DatasetLock;
use App\Services\Sync\TableWriterFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Lock memakai store dari `SYNC_LOCK_STORE`, bukan cache store umum.
         * TTL dibaca dari config tiap pemanggilan, jadi aman diubah di test.
         */
        $this->app->singleton(DatasetLock::class, fn () => new DatasetLock(
            (int) config('oracle-sync.lock.ttl_seconds', 900),
        ));

        $this->app->singleton(TableWriterFactory::class);
    }

    public function boot(): void
    {
        $this->registerLoginThrottle();

        /*
         * Pembatas laju API Tahap 2.
         *
         * Batasnya diambil dari nilai `rate_limit_per_minute` milik token
         * (default 10 per menit), sehingga tiap aplikasi bisa punya batasnya
         * sendiri. Dihitung per token, bukan per IP, supaya beberapa instance
         * aplikasi di IP yang sama tidak saling menghabiskan kuota.
         */
        RateLimiter::for('api.token', function (Request $request) {
            /*
             * Pakhe cache per request yang sama dengan middleware token, jadi
             * kuota dihitung tanpa query kedua ke api_tokens.
             */
            $token = ApiTokenLookup::for($request);

            return Limit::perMinute($token?->rate_limit_per_minute ?? 10)
                ->by($token?->uid ?? 'ip:'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak permintaan. Coba lagi nanti.',
                    'retry_after_seconds' => $headers['Retry-After'] ?? null,
                ], 429, $headers));
        });
    }

    /**
     * Pembatas percobaan login.
     *
     * Dihitung per kombinasi email + IP, bukan per IP saja, supaya satu
     * jaringan korporat tidak saling mengunci padahal orangnya berbeda.
     */
    private function registerLoginThrottle(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = 'login:'.Str::transliterate(
                Str::lower((string) $request->input('email', '')).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($key);
        });
    }
}
