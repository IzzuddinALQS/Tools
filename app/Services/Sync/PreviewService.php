<?php

namespace App\Services\Sync;

use App\Services\Source\SourceReader;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Types\ColumnDefinition;

/**
 * Tombol "Preview": jalankan query dengan batas 20 baris, tampilkan nama kolom,
 * tipe Oracle, tipe PostgreSQL hasil pemetaan, peringatan tipe, dan data.
 *
 * TIDAK APA-APA YANG DISIMPAN.
 */
class PreviewService
{
    public function __construct(
        private readonly ColumnIntrospector $introspector,
    ) {
    }

    /**
     * @param  array<string,string>|null  $columnOverrides
     * @return array<string,mixed>
     */
    public function preview(
        string $sql,
        SourceReader $reader,
        ?string $destinationSchema = null,
        ?string $destinationTable = null,
        ?array $columnOverrides = null,
        int $limit = 20,
    ): array {
        $limit = max(1, min($limit, (int) config('oracle-sync.defaults.preview_rows', 20)));

        $plan = $this->introspector->plan(
            sql: $sql,
            reader: $reader,
            destinationSchema: $destinationSchema,
            destinationTable: $destinationTable,
            columnOverrides: $columnOverrides,
        );

        $rows = [];
        $cursor = $reader->stream($sql, [], $limit, (int) config('oracle-sync.defaults.timeout_seconds', 300));

        foreach ($cursor->rows() as $row) {
            $rendered = [];

            foreach ($plan->columnNames() as $name) {
                $rendered[$name] = $this->render($row[$name] ?? null);
            }

            $rows[] = $rendered;
        }

        return [
            'identity_mode' => $plan->identityMode,
            'source_table' => $plan->sourceTable,
            'schema' => $plan->schema,
            'table' => $plan->table,
            'qualified_table' => $plan->qualifiedTable(),
            'columns' => array_map(fn (PlannedColumn $column) => [
                'name' => $column->name,
                'oracle_type' => $column->oracleType,
                'pg_type' => $column->pgType,
                'from_metadata' => $column->fromMetadata,
                'overridden' => $column->overridden,
                'warnings' => $column->warnings,
            ], $plan->columns),
            'warnings' => $plan->warnings,
            'rows' => $rows,
            'row_count_shown' => count($rows),
            'limit' => $limit,
            'saved' => false,
        ];
    }

    /**
     * @param  array<int,string>  $keyColumns
     * @return array<int,string>
     */
    public function inferKeyColumns(SourceReader $reader, string $schema, string $table): array
    {
        $columns = $reader->tableColumns($schema, $table) ?? [];

        $names = array_map(fn (ColumnDefinition $column) => $column->name, $columns);

        foreach (['PO_HEADER_ID', 'HEADER_ID', 'ID', 'LINE_ID'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return [$candidate];
            }
        }

        return $names === [] ? [] : [$names[0]];
    }

    private function render(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        $text = (string) $value;

        // Nilai panjang dipotong hanya untuk tampilan; nilai yang ditulis ke
        // PostgreSQL tetap utuh.
        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200).' ...' : $text;
    }
}
