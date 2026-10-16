<?php

use App\Http\Controllers\Api\DatasetRefreshController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (Tahap 2)
|--------------------------------------------------------------------------
|
|   POST /api/v1/targets/{target_uid}/datasets/{dataset_uid}/refresh
|   Authorization: Bearer <token>
|
| `{target_uid}` dan `{dataset_uid}` adalah UUID, bukan id berurutan.
|
| Endpoint ini hanya memicu dataset yang SUDAH ADA. Tidak menerima SQL, jadi
| tidak ada jalur untuk menyuntikkan query dari luar: SQL dan pemetaan kolom
| diambil dari versi dataset yang sudah disetujui admin.
|
| Pembatas laju didaftarkan di App\Providers\SyncServiceProvider.
|
| `throttle` sengaja diletakkan lebih dulu: tanpa token yang dikenal, kuota dihitung
| per IP sehingga permintaan anonim ikut terlindungi, bukan lolos ke pencarian
| database tanpa batas.
|
| Token dibuat dengan: php artisan ods:token {app_name}
|
*/

Route::prefix('v1')
    ->middleware(['throttle:api.token', 'api.token'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::post(
            'targets/{target}/datasets/{dataset}/refresh',
            DatasetRefreshController::class
        )->name('refresh');
    });
