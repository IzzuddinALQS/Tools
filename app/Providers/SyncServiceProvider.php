<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Services\Guard\DatasetLock;
use App\Services\Sync\TableWriterFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatasetLock::class, fn ($app) => new DatasetLock(
            (int) config('oracle-sync.lock.ttl_seconds', 900),
        ));

        $this->app->singleton(TableWriterFactory::class);
    }

    public function boot(): void
    {
        /*
         * Pembatas laju API Tahap 2.
         *
         * Batasnya diambil dari nilai `rate_limit_per_minute` milik token
         * (default 10 per menit), sehingga tiap aplikasi bisa punya batasnya
         * sendiri. Dihitung per token, bukan per IP, supaya beberapa instance
         * aplikasi di IP yang sama tidak saling menghabiskan kuota.
         */
        RateLimiter::for('api.token', function (Request $request) {
            $header = (string) $request->header('Authorization', '');
            $plain = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';

            $token = $plain === '' ? null : ApiToken::findByPlainToken($plain);

            return Limit::perMinute($token?->rate_limit_per_minute ?? 10)
                ->by($token?->uid ?? 'ip:'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak permintaan. Coba lagi nanti.',
                    'retry_after_seconds' => $headers['Retry-After'] ?? null,
                ], 429, $headers));
        });
    }
}
