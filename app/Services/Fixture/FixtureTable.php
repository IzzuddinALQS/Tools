<?php

namespace App\Services\Fixture;

use App\Services\Fixture\Exceptions\FixtureException;
use App\Services\Types\ColumnDefinition;

/**
 * Satu tabel fixture: metadata kolom gaya Oracle + baris.
 */
final class FixtureTable
{
    /**
     * @param  array<int,ColumnDefinition>  $columns
     * @param  array<int,array<string,string>>  $rows
     * @param  array<int,string>  $binaryColumns  Kolom yang nilainya disandikan base64.
     */
    public function __construct(
        private readonly string $schema,
        private readonly string $table,
        private readonly array $columns,
        private readonly array $rows,
        private readonly array $binaryColumns = [],
    ) {
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    public static function fromArray(array $raw, string $defaultSchema, int $index): self
    {
        $table = strtoupper((string) ($raw['name'] ?? ''));

        if ($table === '') {
            throw new FixtureException("Fixture tabel ke-{$index} tidak punya name.");
        }

        $schema = strtoupper((string) ($raw['schema'] ?? $defaultSchema));

        $columns = [];
        $binaryColumns = [];
        $position = 0;

        foreach ((array) ($raw['columns'] ?? []) as $columnIndex => $columnRaw) {
            $columnRaw = (array) $columnRaw;
            $position++;

            $name = strtoupper((string) ($columnRaw['name'] ?? ''));

            if ($name === '') {
                throw new FixtureException("Kolom ke-{$columnIndex} pada tabel {$schema}.{$table} tidak punya name.");
            }

            $encoding = strtolower((string) ($columnRaw['encoding'] ?? ''));

            if ($encoding === 'base64') {
                $binaryColumns[] = $name;
            }

            $columns[] = new ColumnDefinition(
                name: $name,
                oracleType: strtoupper((string) ($columnRaw['oracle_type'] ?? 'VARCHAR2')),
                dataLength: isset($columnRaw['data_length']) ? (int) $columnRaw['data_length'] : null,
                charLength: isset($columnRaw['char_length']) ? (int) $columnRaw['char_length'] : null,
                charUsed: isset($columnRaw['char_used']) ? (int) $columnRaw['char_used'] : null,
                dataPrecision: isset($columnRaw['data_precision']) ? (int) $columnRaw['data_precision'] : null,
                dataScale: isset($columnRaw['data_scale']) ? (int) $columnRaw['data_scale'] : null,
                position: (int) ($columnRaw['position'] ?? $position),
            );
        }

        if ($columns === []) {
            throw new FixtureException("Tabel {$schema}.{$table} tidak punya kolom.");
        }

        $columnNames = array_map(fn (ColumnDefinition $column) => $column->name, $columns);

        $rows = [];

        foreach ((array) ($raw['rows'] ?? []) as $rowRaw) {
            $rowRaw = (array) $rowRaw;
            $row = [];

            foreach ($columnNames as $name) {
                $value = $rowRaw[$name] ?? null;
                $row[$name] = $value === null ? null : (string) $value;
            }

            $rows[] = $row;
        }

        return new self($schema, $table, $columns, $rows, $binaryColumns);
    }

    public function schema(): string
    {
        return $this->schema;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->table;
    }

    /**
     * @return array<int,ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int,string>
     */
    public function columnNames(): array
    {
        return array_map(fn (ColumnDefinition $column) => $column->name, $this->columns);
    }

    public function hasColumn(string $name): bool
    {
        return in_array(strtoupper($name), $this->columnNames(), true);
    }

    /**
     * @return array<int,array<string,string|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int,string>
     */
    public function binaryColumns(): array
    {
        return $this->binaryColumns;
    }
}
