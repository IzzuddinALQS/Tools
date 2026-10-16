<?php

namespace App\Providers;

use App\Services\Guard\DatasetLock;
use App\Services\Sync\TableWriterFactory;
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
        //
    }
}
