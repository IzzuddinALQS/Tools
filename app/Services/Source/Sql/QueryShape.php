<?php

namespace App\Services\Source\Sql;

/**
 * Hasil analisis bentuk query.
 */
final class QueryShape
{
    /**
     * @param  array<int,string>  $selectedColumns  Kolom yang dipilih, huruf
     *                                                  besar, TANPA nama tabel.
     * @param  array<int,string>  $reasons          Alasan mode identitas tidak bisa dipakai.
     * @param  array<int,string>  $referencedTables Tabel yang disebut di FROM/JOIN.
     */
    public function __construct(
        public readonly bool $isSingleTable,
        public readonly ?string $baseTable,
        public readonly array $selectedColumns,
        public readonly array $reasons,
        public readonly array $referencedTables = [],
        public readonly bool $hasAlias = false,
        public readonly bool $hasExpression = false,
    ) {
    }

    public function reasonSummary(): string
    {
        return implode(' ', $this->reasons);
    }
}
