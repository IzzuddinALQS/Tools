<?php

namespace App\Services\Source;

use App\Services\Types\ColumnDefinition;
use Generator;

/**
 * Hasil satu statement SELECT: metadata kolom + generator baris yang
 * di-stream (tidak dimuat sekaligus ke memori).
 */
final class SourceCursor
{
    /**
     * @param  array<int,ColumnDefinition>  $columns
     * @param  Generator<int,array<string,mixed>>  $rows
     */
    public function __construct(
        private readonly array $columns,
        private readonly Generator $rows,
    ) {
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

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function rows(): Generator
    {
        return $this->rows;
    }
}
