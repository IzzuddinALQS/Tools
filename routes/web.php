<?php

use App\Http\Controllers\CopyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PauseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| Halaman internal: wajib login. Halaman Driver hanya untuk admin.
*/

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest')->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('/pause', [PauseController::class, 'store'])->name('pause.store');
    Route::delete('/pause', [PauseController::class, 'destroy'])->name('pause.destroy');
    Route::get('/pause', [PauseController::class, 'index'])->name('pause.index');

    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('/history/{run}', [HistoryController::class, 'show'])->name('history.show');

    Route::get('/copy', [CopyController::class, 'index'])->name('copy.index');
    Route::post('/copy', [CopyController::class, 'store'])->name('copy.store');

    Route::post('/datasets/preview', [DatasetController::class, 'preview'])->name('datasets.preview');

    Route::get('/datasets', [DatasetController::class, 'index'])->name('datasets.index');
    Route::get('/datasets/create', [DatasetController::class, 'create'])->name('datasets.create');
    Route::post('/datasets', [DatasetController::class, 'store'])->name('datasets.store');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->name('datasets.show');
    Route::put('/datasets/{dataset}', [DatasetController::class, 'update'])->name('datasets.update');
    Route::post('/datasets/{dataset}/run', [DatasetController::class, 'run'])->name('datasets.run');
    Route::post('/datasets/{dataset}/toggle', [DatasetController::class, 'toggleStatus'])->name('datasets.toggle');
    Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('datasets.destroy');

    // Hanya admin (Keamanan 3).
    Route::middleware('admin')->group(function (): void {
        Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::post('/drivers/connections', [DriverController::class, 'storeConnection'])->name('drivers.connections.store');
        Route::put('/drivers/connections/{connection}', [DriverController::class, 'updateConnection'])->name('drivers.connections.update');
        Route::delete('/drivers/connections/{connection}', [DriverController::class, 'destroyConnection'])->name('drivers.connections.destroy');
        Route::post('/drivers/connections/{connection}/test', [DriverController::class, 'testConnection'])->name('drivers.connections.test');

        Route::post('/drivers/targets', [DriverController::class, 'storeTarget'])->name('drivers.targets.store');
        Route::put('/drivers/targets/{target}', [DriverController::class, 'updateTarget'])->name('drivers.targets.update');
        Route::delete('/drivers/targets/{target}', [DriverController::class, 'destroyTarget'])->name('drivers.targets.destroy');
        Route::post('/drivers/targets/{target}/test', [DriverController::class, 'testTarget'])->name('drivers.targets.test');
    });
});
