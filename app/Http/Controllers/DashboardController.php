<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Run;
use App\Services\Api\RuntimeStatus;
use App\Services\Guard\KillSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, KillSwitch $killSwitch): Response
    {
        return Inertia::render('Dashboard', [
            'pause' => $killSwitch->state(),
            'stats' => [
                'connections' => \App\Models\Connection::count(),
                'targets' => \App\Models\Target::count(),
                'datasets' => Dataset::count(),
                'datasets_active' => Dataset::where('status', 'active')->count(),
            ],
            'runtime' => $this->runtime(),
            'recentRuns' => Run::query()
                ->with(['dataset:id,name', 'target:id,name'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Run $run) => [
                    'id' => $run->id,
                    'dataset' => $run->dataset?->name,
                    'kind' => $run->kind->value,
                    'status' => $run->status->value,
                    'verification' => $run->verificationBadge(),
                    'row_count' => $run->row_count,
                    'finished_at' => $run->finished_at?->toDateTimeString(),
                ]),
            'datasets' => Dataset::query()
                ->with(['connection:id,name,driver', 'target:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Dataset $dataset) => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                    'destination' => $dataset->qualifiedDestination(),
                    'sync_mode' => $dataset->sync_mode->value,
                    'status' => $dataset->status->value,
                    'connection' => $dataset->connection?->name,
                    'driver' => $dataset->connection?->driver->value,
                    'last_success_at' => $dataset->last_success_at?->toDateTimeString(),
                    'last_row_count' => $dataset->last_row_count,
                    'schedule_cron' => $dataset->schedule_cron,
                    'reconcile_cron' => $dataset->reconcile_cron,
                ]),
        ]);
    }

    /**
     * Apakah jadwal otomatis benar-benar berjalan.
     *
     * - scheduler: heartbeat dicatat `schedule:run` tiap menit
     *   (lihat routes/console.php). Basi = cron/scheduler mati.
     * - worker: job yang menunggu lebih dari 2 menit di tabel `jobs` berarti
     *   tidak ada `queue:work` yang mengambilnya.
     *
     * @return array<string,mixed>
     */
    private function runtime(): array
    {
        $queue = ['checked' => false, 'pending' => 0, 'oldest_age' => null];

        if (config('queue.default') === 'database') {
            $table = config('queue.connections.database.table', 'jobs');

            try {
                $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
                $queue = [
                    'checked' => true,
                    'pending' => DB::table($table)->count(),
                    'oldest_age' => $oldest ? max(0, now()->timestamp - (int) $oldest) : null,
                ];
            } catch (\Throwable) {
                // Tabel antrean belum dimigrasi: anggap tidak diketahui.
            }
        }

        return [
            'scheduler' => RuntimeStatus::workerStatus(),
            'queue' => $queue,
        ];
    }
}
