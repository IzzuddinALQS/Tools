<?php

namespace App\Http\Controllers;

use App\Enums\RunTriggerEnum;
use App\Models\Dataset;
use App\Models\Run;
use App\Models\Target;
use App\Services\Copy\CopyRunner;
use App\Services\Sync\SyncRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Copy PostgreSQL A -> PostgreSQL B, dan tombol "Pause semua job".
 */
class CopyController extends Controller
{
    public function index(Request $request): Response
    {
        $datasets = Dataset::query()
            ->with(['connection:id,name', 'target:id,name'])
            ->orderBy('name')
            ->get();

        $targets = Target::where('kind', 'pg_b')->orderBy('app_name')->get();

        return Inertia::render('Copy', [
            'datasets' => $datasets->map(fn (Dataset $dataset) => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'destination' => $dataset->qualifiedDestination(),
                'has_data' => $dataset->last_success_at !== null,
                'last_success_at' => $dataset->last_success_at?->toDateTimeString(),
                'auto_copy_to' => $dataset->auto_copy_to ?? [],
            ]),
            'targets' => $targets->map(fn (Target $target) => [
                'id' => $target->id,
                'name' => $target->name,
                'app_name' => $target->app_name,
                'is_active' => $target->is_active,
                'readonly_username' => $target->readonly_username,
                'has_readonly' => $target->readonly_username !== null,
                'readonly_provisioned_at' => $target->readonly_provisioned_at?->toDateTimeString(),
            ]),
            'lastCopies' => Run::where('kind', 'copy')
                ->with(['dataset:id,name', 'target:id,name'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Run $run) => [
                    'id' => $run->id,
                    'dataset' => $run->dataset?->name,
                    'target' => $run->target?->name,
                    'status' => $run->status->value,
                    'verification' => $run->verificationBadge(),
                    'row_count' => $run->row_count,
                    'error_message' => $run->error_message,
                    'finished_at' => $run->finished_at?->toDateTimeString(),
                ]),
        ]);
    }

    public function store(Request $request, CopyRunner $copies): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'integer', 'exists:datasets,id'],
            'target_id' => ['required', 'integer', 'exists:targets,id'],
        ]);

        $dataset = Dataset::with(['target'])->findOrFail($data['dataset_id']);
        $target = Target::findOrFail($data['target_id']);

        if (! $target->isPgB()) {
            return back()->withErrors([
                'target_id' => 'Target harus PostgreSQL B. PostgreSQL A adalah sumbernya.',
            ]);
        }

        $run = $copies->run($dataset, $target, RunTriggerEnum::Manual, $request->user()?->id);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $run->status->value === 'success',
                'status' => $run->status->value,
                'run_id' => $run->id,
                'row_count' => $run->row_count,
                'verification' => $run->verification,
                'error_message' => $run->error_message,
            ]);
        }

        $message = match ($run->status->value) {
            'success' => 'Copy selesai: '.$run->row_count.' baris, verifikasi '
                .($run->verificationBadge() === 'ok' ? 'berhasil' : 'GAGAL').'.',
            'skipped' => 'Copy ke target ini sedang berjalan.',
            'paused' => 'Semua job sedang di-pause. Tidak ada penulisan.',
            default => 'Copy gagal: '.$run->error_message,
        };

        return back()->with(
            $run->status->value === 'success' ? 'success' : 'error',
            $message,
        );
    }
}
