<?php

namespace App\Services\Sync;

use App\Models\PgIdentifier;

/**
 * Rencana lengkap untuk satu sinkronisasi: ke mana menulis, dengan kolom apa,
 * dan peringatan apa yang harus tampil di UI.
 */
final class SyncPlan
{
    /**
     * @param  array<int,PlannedColumn>  $columns
     * @param  array<int,string>  $warnings
     */
    public function __construct(
        public readonly string $schema,
        public readonly string $table,
        public readonly bool $identityMode,
        public readonly array $columns,
        public readonly array $warnings = [],
        public readonly ?string $sourceTable = null,
        public readonly ?string $shapeSummary = null,
    ) {
    }

    public function qualifiedTable(): string
    {
        return PgIdentifier::qualify($this->schema, $this->table);
    }

    public function columnNames(): array
    {
        return array_map(fn (PlannedColumn $column) => $column->name, $this->columns);
    }

    /**
     * @return array<string,string> nama kolom => tipe PostgreSQL
     */
    public function typeMap(): array
    {
        $map = [];

        foreach ($this->columns as $column) {
            $map[$column->name] = $column->pgType;
        }

        return $map;
    }

    /**
     * @return array<int,string>
     */
    public function pgTypes(): array
    {
        return array_map(fn (PlannedColumn $column) => $column->pgType, $this->columns);
    }

    /**
     * @return array<int,string>
     */
    public function columnWarnings(): array
    {
        $warnings = [];

        foreach ($this->columns as $column) {
            foreach ($column->warnings as $warning) {
                $warnings[] = $column->name.': '.$warning;
            }
        }

        return $warnings;
    }

    /**
     * CREATE TABLE untuk tabel staging / final.
     */
    public function createTableSql(string $qualifiedTable): string
    {
        $definitions = array_map(
            fn (PlannedColumn $column) => '    '.$column->columnDefinitionSql(),
            $this->columns,
        );

        return sprintf(
            "CREATE TABLE %s (\n%s\n)",
            $qualifiedTable,
            implode(",\n", $definitions)
        );
    }

    public function allWarnings(): array
    {
        return array_values(array_unique(array_merge($this->warnings, $this->columnWarnings())));
    }
}
