<?php

namespace App\Services\Sync;

use App\Services\Source\Exceptions\SyncException;
use App\Services\Source\SourceReader;
use App\Services\Source\Sql\BaseTableDetector;
use App\Services\Source\Sql\SelectGuard;
use App\Services\Types\ColumnDefinition;
use App\Services\Types\OracleTypeMapper;
use App\Services\Types\PgType;
use App\Services\Types\TypeCategory;

/**
 * Menyusun rencana sync: ke mana menulis, kolom apa, tipenya apa.
 *
 * Aturan 1 & 2:
 *   - query yang membaca SATU tabel Oracle -> nama schema/tabel/kolom IDENTIK,
 *     diambil dari metadata (ALL_TAB_COLUMNS), bukan ditebak dari isi data
 *   - query JOIN/agregasi/ber-alias -> user WAJIB memberi nama tabel sendiri
 *
 * Kalau ragu, plan selalu jatuh ke mode non-identitas: lebih baik minta user
 * menyebut nama tabel daripada salah menebak nama tabel aplikasi.
 */
class ColumnIntrospector
{
    public function __construct(
        private readonly SelectGuard $guard,
        private readonly BaseTableDetector $detector,
        private readonly OracleTypeMapper $mapper,
    ) {
    }

    /**
     * @param  array<string,string>|null  $columnOverrides  { NAMA_KOLOM => 'numeric(20,4)' }
     */
    public function plan(
        string $sql,
        SourceReader $reader,
        ?string $destinationSchema = null,
        ?string $destinationTable = null,
        ?array $columnOverrides = null,
    ): SyncPlan {
        $this->guard->assert($sql);

        $shape = $this->detector->analyze($sql);
        $warnings = [];

        // --- MODE IDENTITAS: satu tabel Oracle ---
        if ($shape->isSingleTable && $shape->baseTable !== null) {
            $plan = $this->planIdentity($sql, $reader, $shape->baseTable, $shape->selectedColumns, $warnings);

            if ($plan !== null) {
                return $this->applyOverrides($plan, $columnOverrides ?? []);
            }
        }

        // --- MODE NON-IDENTITAS: user wajib memberi nama sendiri ---
        if ($destinationSchema === null || $destinationSchema === '' || $destinationTable === null || $destinationTable === '') {
            throw new SyncException(
                'Query ini tidak bisa memakai nama tabel yang identik dengan Oracle. '
                .'Alasan: '.$shape->reasonSummary().' '
                .'Karena itu Anda WAJIB memberi nama tabel tujuan sendiri.',
                'destination_name_required',
                ['reasons' => $shape->reasons],
            );
        }

        $warnings[] = 'Query tidak membaca satu tabel Oracle ('.$shape->reasonSummary().'), '
            .'sehingga nama dan tipe kolom tidak diambil dari metadata Oracle.';

        $columns = $this->columnsFromCursor($sql, $reader, $warnings);

        $plan = new SyncPlan(
            schema: $destinationSchema,
            table: $destinationTable,
            identityMode: false,
            columns: $columns,
            warnings: $warnings,
            sourceTable: $shape->baseTable,
            shapeSummary: $shape->reasonSummary(),
        );

        return $this->applyOverrides($plan, $columnOverrides ?? []);
    }

    /**
     * @param  array<int,string>  $warnings
     */
    private function planIdentity(
        string $sql,
        SourceReader $reader,
        string $baseTable,
        array $selectedColumns,
        array &$warnings,
    ): ?SyncPlan {
        [$owner, $table] = $this->splitBaseTable($baseTable, $reader);

        $metadata = $reader->tableColumns($owner, $table);

        if ($metadata === null) {
            $warnings[] = "Tabel {$baseTable} tidak ditemukan atau tidak terlihat oleh user Oracle. "
                .'Nama dan tipe kolom tidak bisa diambil dari metadata, jadi mode identitas dipakai.';

            return null;
        }

        $byName = [];

        foreach ($metadata as $definition) {
            $byName[strtoupper($definition->name)] = $definition;
        }

        $selected = $this->resolveSelectedColumns($selectedColumns, $byName, $warnings, $baseTable);

        if ($selected === null) {
            return null;
        }

        $columns = [];

        foreach ($selected as $definition) {
            $mapping = $this->mapper->map($definition);

            $columns[] = new PlannedColumn(
                name: $definition->name,
                pgType: $mapping->pgType,
                category: $mapping->category,
                oracleType: $definition->oracleType,
                warnings: $mapping->warnings,
                fromMetadata: true,
            );
        }

        return new SyncPlan(
            schema: $owner,
            table: $table,
            identityMode: true,
            columns: $columns,
            warnings: $warnings,
            sourceTable: $baseTable,
        );
    }

    /**
     * @param  array<string,ColumnDefinition>  $byName
     * @param  array<int,string>  $warnings
     * @return array<int,ColumnDefinition>|null
     */
    private function resolveSelectedColumns(
        array $selectedColumns,
        array $byName,
        array &$warnings,
        string $baseTable,
    ): ?array {
        // SELECT * : semua kolom metadata, sesuai urutan Oracle.
        if ($selectedColumns === ['*']) {
            return array_values($byName);
        }

        if ($selectedColumns === []) {
            return null;
        }

        $resolved = [];

        foreach ($selectedColumns as $name) {
            $key = strtoupper($name);

            if (! isset($byName[$key])) {
                $warnings[] = "Kolom {$name} tidak ada di metadata {$baseTable}; mode identitas tidak bisa dipakai.";

                return null;
            }

            $resolved[] = $byName[$key];
        }

        return $resolved;
    }

    /**
     * @param  array<int,string>  $warnings
     * @return array<int,PlannedColumn>
     */
    private function columnsFromCursor(string $sql, $reader, array &$warnings): array
    {
        try {
            $cursor = $reader->stream($sql, [], 1, (int) config('oracle-sync.defaults.timeout_seconds', 300));
            $names = $cursor->columnNames();
            // Generator harus-dihentikan supaya kursor ditutup.
            foreach ($cursor->rows() as $_) {
                break;
            }
        } catch (SyncException $exception) {
            throw $exception;
        }

        if ($names === []) {
            throw new SyncException(
                'Query tidak mengembalikan kolom apa pun.',
                'no_columns',
            );
        }

        $columns = [];

        foreach ($names as $index => $name) {
            $columns[] = new PlannedColumn(
                name: $name,
                pgType: 'text',
                category: TypeCategory::Text,
                oracleType: null,
                warnings: ['Tipe kolom belum dikonfirmasi. Default text; '
                    .'ubah lewat kolom "Konfirmasi tipe" bila perlu kolom numerik atau waktu.'],
            );
        }

        return $columns;
    }

    /**
     * @return array{0:string,1:string} owner, table
     */
    private function splitBaseTable(string $baseTable, SourceReader $reader): array
    {
        $parts = explode('.', $baseTable);

        if (count($parts) === 2) {
            return [strtoupper($parts[0]), strtoupper($parts[1])];
        }

        return [strtoupper($this->defaultOwner($reader)), strtoupper($parts[0])];
    }

    /**
     * Schema default milik user Oracle saat tidak ditulis di query.
     */
    private function defaultOwner(SourceReader $reader): string
    {
        if ($reader instanceof \App\Services\Source\MockOracleReader) {
            return $reader->catalog()->defaultSchema();
        }

        return 'APPUSER';
    }

    /**
     * Terapkan koreksi tipe dari admin (query JOIN/agregasi).
     *
     * @param  array<string,string>  $overrides
     */
    private function applyOverrides(SyncPlan $plan, array $overrides): SyncPlan
    {
        if ($overrides === []) {
            return $plan;
        }

        $columns = [];

        foreach ($plan->columns as $column) {
            $key = strtoupper($column->name);

            if (! isset($overrides[$key])) {
                $columns[] = $column;
                continue;
            }

            $pgType = trim((string) $overrides[$key]);

            if ($pgType === '') {
                $columns[] = $column;
                continue;
            }

            $columns[] = new PlannedColumn(
                name: $column->name,
                pgType: $pgType,
                category: PgType::categoryOf($pgType),
                oracleType: $column->oracleType,
                warnings: $column->warnings,
                fromMetadata: $column->fromMetadata,
                overridden: true,
            );
        }

        return new SyncPlan(
            schema: $plan->schema,
            table: $plan->table,
            identityMode: $plan->identityMode,
            columns: $columns,
            warnings: $plan->warnings,
            sourceTable: $plan->sourceTable,
            shapeSummary: $plan->shapeSummary,
        );
    }
}
