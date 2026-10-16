<?php

namespace App\Http\Controllers;

use App\Models\Run;
use App\Services\Guard\KillSwitch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['dataset_id', 'status', 'kind', 'verification']);

        $runs = Run::query()
            ->with(['dataset:id,name', 'connection:id,name', 'target:id,name', 'triggeredByUser:id,name'])
            ->when($filters['dataset_id'] ?? null, fn ($q, $v) => $q->where('dataset_id', (int) $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when(($filters['verification'] ?? null) === 'failed', fn ($q) => $q->whereNotNull('verification')->where('verification->matched', false))
            ->when(($filters['verification'] ?? null) === 'ok', fn ($q) => $q->whereNotNull('verification')->where('verification->matched', true))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('History', [
            'filters' => $filters,
            'runs' => $runs->through(fn (Run $run) => [
                'id' => $run->id,
                'dataset' => $run->dataset?->name,
                'kind' => $run->kind->value,
                'trigger' => $run->trigger->value,
                'status' => $run->status->value,
                'target' => $run->target?->name,
                'connection' => $run->connection?->name,
                'triggered_by' => $run->triggeredByUser?->name,
                'row_count' => $run->row_count,
                'verification' => $run->verificationBadge(),
                'verification_detail' => $run->verification,
                'duration_ms' => $run->duration_ms,
                'error_code' => $run->error_code,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at?->toDateTimeString(),
                'finished_at' => $run->finished_at?->toDateTimeString(),
                'sql_hash' => $run->sql_hash,
            ]),
            'datasets' => \App\Models\Dataset::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(Run $run): Response
    {
        $run->load(['dataset:id,name', 'connection:id,name', 'target:id,name', 'triggeredByUser:id,name']);

        return Inertia::render('RunDetail', [
            'run' => [
                'id' => $run->id,
                'dataset' => $run->dataset?->name,
                'kind' => $run->kind->value,
                'trigger' => $run->trigger->value,
                'status' => $run->status->value,
                'target' => $run->target?->name,
                'connection' => $run->connection?->name,
                'triggered_by' => $run->triggeredByUser?->name,
                'row_count' => $run->row_count,
                'duration_ms' => $run->duration_ms,
                'sql_text' => $run->sql_text,
                'sql_hash' => $run->sql_hash,
                'verification' => $run->verification,
                'error_code' => $run->error_code,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at?->toDateTimeString(),
                'finished_at' => $run->finished_at?->toDateTimeString(),
            ],
        ]);
    }
}
