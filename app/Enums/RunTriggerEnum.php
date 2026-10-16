<?php

namespace App\Enums;

/**
 * Pemicu eksekusi. `api` disiapkan untuk Tahap 2
 * (POST /api/v1/targets/{target}/datasets/{dataset}/refresh).
 */
enum RunTriggerEnum: string
{
    case Manual = 'manual';
    case Schedule = 'schedule';
    case Api = 'api';
    case Reconcile = 'reconcile';
}
