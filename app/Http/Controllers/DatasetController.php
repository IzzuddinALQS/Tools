<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatusEnum;
use App\Enums\SyncModeEnum;
use App\Jobs\SyncDatasetJob;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Source\SourceReaderFactory;
use App\Services\Sync\ColumnIntrospector;
use App\Services\Sync\DatasetManager;
use App\Services\Sync\PreviewService;
use App\Services\Sync\SyncRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SQL Editor: menulis query Oracle, melihat Preview, lalu menyimpan dataset.
 */
class DatasetController extends Controller
{
    public function __construct(
        private readonly SourceReaderFactory $readers,
        private readonly PreviewService $preview,
        private readonly DatasetManager $datasets,
        private readonly ColumnIntrospector $introspector,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Datasets', [
            'datasets' => Dataset::query()
                ->with(['connection:id,name,driver', 'target:id,name,kind'])
                ->orderBy('name')
                ->get()
                ->map(fn (Dataset $dataset) => $this->summary($dataset)),
            'connections' => Connection::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'driver'])
                ->map(fn (Connection $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'driver' => $c->driver->value,
                ]),
            'targets' => Target::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'kind', 'app_name'])
                ->map(fn (Target $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'kind' => $t->kind->value,
                    'app_name' => $t->app_name,
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('DatasetEditor', [
            'dataset' => null,
            'connections' => $this->connectionOptions(),
            'targets' => $this->targetOptions(),
            'copyTargets' => $this->copyTargetOptions(),
            'defaults' => $this->defaults(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        try {
            $result = $this->datasets->create($data, $request->user()?->id);
        } catch (SyncException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sql' => $this->messageFrom($exception)]);
        }

        return redirect()
            ->route('datasets.show', $result['dataset']->id)
            ->with('success', 'Dataset "'.$result['dataset']->name.'" dibuat. '
                .'Klik "Jalankan sinkronisasi" untuk mengisi PostgreSQL A.');
    }

    public function show(Request $request, Dataset $dataset): Response
    {
        $dataset->load(['connection', 'target', 'versions.changedByUser:id,name']);

        return Inertia::render('DatasetEditor', [
            'dataset' => $this->detail($dataset),
            'versions' => $dataset->versions->map(fn ($version) => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'sql' => $version->sql,
                'change_type' => $version->change_type,
                'note' => $version->note,
                'created_at' => $version->created_at?->toDateTimeString(),
                'changed_by' => $version->changedByUser?->name,
            ]),
            'connections' => $this->connectionOptions(),
            'targets' => $this->targetOptions(),
            'copyTargets' => $this->copyTargetOptions(),
            'defaults' => $this->defaults(),
            'recentRuns' => $dataset->runs()->limit(5)->get()->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status->value,
                'kind' => $run->kind->value,
                'trigger' => $run->trigger->value,
                'row_count' => $run->row_count,
                'verification' => $run->verificationBadge(),
                'error_message' => $run->error_message,
                'started_at' => $run->started_at?->toDateTimeString(),
                'duration_ms' => $run->duration_ms,
            ]),
        ]);
    }

    public function update(Request $request, Dataset $dataset): RedirectResponse
    {
        $data = $this->validatePayload($request, $dataset->id);

        try {
            $this->datasets->update($dataset, $data, $request->user()?->id);
        } catch (SyncException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sql' => $this->messageFrom($exception)]);
        }

        return back()->with('success', 'Dataset diperbarui. Perubahan tersimpan sebagai versi baru.');
    }

    public function destroy(Dataset $dataset): RedirectResponse
    {
        $dataset->delete();

        return redirect()
            ->route('datasets.index')
            ->with('success', 'Dataset dihapus. Tabel di PostgreSQL A tidak ikut terhapus.');
    }

    /**
     * Preview: jalankan query dengan batas 20 baris. Tidak menyimpan apa pun.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:connections,id'],
            'sql' => ['required', 'string', 'max:100000'],
            'destination_schema' => ['nullable', 'string', 'max:150'],
            'destination_table' => ['nullable', 'string', 'max:150'],
            'column_overrides' => ['nullable', 'array'],
        ]);

        $connection = Connection::findOrFail($data['connection_id']);

        try {
            $preview = $this->preview->preview(
                sql: $data['sql'],
                reader: $this->readers->for($connection),
                destinationSchema: $data['destination_schema'] ?? null,
                destinationTable: $data['destination_table'] ?? null,
                columnOverrides: $data['column_overrides'] ?? null,
            );
        } catch (SyncException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->messageFrom($exception),
            ], 422);
        }

        return response()->json(['ok' => true, 'preview' => $preview]);
    }

    /**
     * Jalankan sinkronisasi sekarang.
     */
    public function run(Request $request, Dataset $dataset, SyncRunner $runner): RedirectResponse|JsonResponse
    {
        $mode = $request->string('mode')->toString();
        $forceFull = $mode === 'full' && $dataset->isIncremental();

        $run = $runner->run(
            $dataset,
            \App\Enums\RunTriggerEnum::Manual,
            $forceFull ? \App\Enums\RunKindEnum::Reconcile : \App\Enums\RunKindEnum::Sync,
            $request->user()?->id,
            $forceFull,
        );

        if ($request->expectsJson()) {
            return response()->json($this->runPayload($run));
        }

        $message = match ($run->status->value) {
            'success' => 'Sinkronisasi selesai: '.$run->row_count.' baris, verifikasi '
                .($run->verificationBadge() === 'ok' ? 'berhasil' : 'GAGAL').'.',
            'skipped' => 'Dataset sedang berjalan di proses lain.',
            'paused' => 'Semua job sedang di-pause. Tidak ada penulisan.',
            default => 'Sinkronisasi gagal: '.$run->error_message,
        };

        return back()->with(
            $run->status->value === 'success' ? 'success' : 'error',
            $message,
        );
    }

    public function toggleStatus(Request $request, Dataset $dataset): RedirectResponse
    {
        $status = $dataset->status === DatasetStatusEnum::Active
            ? DatasetStatusEnum::Paused
            : DatasetStatusEnum::Active;

        $dataset->update(['status' => $status]);

        return back()->with('success', 'Dataset '.($status->value === 'active' ? 'diaktifkan' : 'dijeda').'.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('datasets', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'connection_id' => ['required', 'integer', 'exists:connections,id'],
            'target_id' => ['required', 'integer', 'exists:targets,id'],
            'sql' => ['required', 'string', 'max:100000'],
            'destination_schema' => ['nullable', 'string', 'max:150'],
            'destination_table' => ['nullable', 'string', 'max:150'],
            'column_overrides' => ['nullable', 'array'],
            'sync_mode' => ['required', Rule::in([SyncModeEnum::Full->value, SyncModeEnum::Incremental->value])],
            'watermark_column' => ['nullable', 'string', 'max:150'],
            'watermark_type' => ['nullable', 'string', 'max:30'],
            'key_columns' => ['nullable', 'array'],
            'key_columns.*' => ['string', 'max:150'],
            'schedule_cron' => ['nullable', 'string', 'max:100'],
            'reconcile_cron' => ['nullable', 'string', 'max:100'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'max_rows' => ['required', 'integer', 'min:1', 'max:100000000'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:100000'],
            'auto_copy_to' => ['nullable', 'array'],
            'auto_copy_to.*' => ['integer', 'exists:targets,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $validated;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function connectionOptions(): array
    {
        return Connection::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'driver'])
            ->map(fn (Connection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'driver' => $c->driver->value,
                'driver_label' => $c->driver->label(),
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function targetOptions(): array
    {
        return Target::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'kind', 'app_name'])
            ->map(fn (Target $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'kind' => $t->kind->value,
                'app_name' => $t->app_name,
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function copyTargetOptions(): array
    {
        return Target::where('kind', 'pg_b')->orderBy('app_name')
            ->get(['id', 'name', 'app_name'])
            ->map(fn (Target $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'app_name' => $t->app_name,
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return [
            'sync_modes' => [
                ['value' => SyncModeEnum::Full->value, 'label' => 'Full (disarankan)'],
                ['value' => SyncModeEnum::Incremental->value, 'label' => 'Incremental (butuh watermark)'],
            ],
            'schedule_cron' => config('oracle-sync.defaults.schedule_cron'),
            'reconcile_cron' => config('oracle-sync.defaults.reconcile_cron'),
            'timeout_seconds' => config('oracle-sync.defaults.timeout_seconds'),
            'max_rows' => config('oracle-sync.defaults.max_rows'),
            'batch_size' => config('oracle-sync.defaults.batch_size'),
            'preview_rows' => config('oracle-sync.defaults.preview_rows'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'destination' => $dataset->qualifiedDestination(),
            'identity_mode' => $dataset->identity_mode,
            'sync_mode' => $dataset->sync_mode->value,
            'status' => $dataset->status->value,
            'connection' => $dataset->connection?->name,
            'driver' => $dataset->connection?->driver->value,
            'target' => $dataset->target?->name,
            'schedule_cron' => $dataset->schedule_cron,
            'reconcile_cron' => $dataset->reconcile_cron,
            'last_success_at' => $dataset->last_success_at?->toDateTimeString(),
            'last_row_count' => $dataset->last_row_count,
            'auto_copy_to' => $dataset->auto_copy_to ?? [],
            'warnings' => $dataset->incrementalWarnings(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function detail(Dataset $dataset): array
    {
        return [
            ...$this->summary($dataset),
            'sql' => $dataset->sql,
            'connection_id' => $dataset->connection_id,
            'target_id' => $dataset->target_id,
            'destination_schema' => $dataset->destination_schema,
            'destination_table' => $dataset->destination_table,
            'column_overrides' => $dataset->column_overrides ?? [],
            'watermark_column' => $dataset->watermark_column,
            'watermark_type' => $dataset->watermark_type,
            'key_columns' => $dataset->key_columns ?? [],
            'timeout_seconds' => $dataset->timeout_seconds,
            'max_rows' => $dataset->max_rows,
            'batch_size' => $dataset->batch_size,
            'current_version_no' => $dataset->current_version_no,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runPayload(\App\Models\Run $run): array
    {
        return [
            'ok' => $run->status->value === 'success',
            'status' => $run->status->value,
            'run_id' => $run->id,
            'row_count' => $run->row_count,
            'verification' => $run->verification,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
        ];
    }

    /**
     * Pesan exception sudah aman (tidak memuat kredensial), tapi tetap
     * dibersihkan dari sisa yang tidak perlu.
     */
    private function messageFrom(SyncException $exception): string
    {
        $message = mb_substr($exception->getMessage(), 0, 1000);

        return (string) preg_replace(
            '/\b(?:password|passwd|pwd)\s*[=:]\s*\S+/i',
            'password=***',
            $message
        );
    }
}
