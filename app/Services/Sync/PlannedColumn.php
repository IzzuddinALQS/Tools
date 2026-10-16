<?php

namespace App\Services\Sync;

use App\Models\PgIdentifier;
use App\Services\Types\TypeCategory;

/**
 * Satu kolom yang sudah diputuskan akan dibuat di PostgreSQL.
 */
final class PlannedColumn
{
    /**
     * @param  array<int,string>  $warnings
     * @param  bool  $fromMetadata  true bila nama & tipe diambil dari metadata
     *                              Oracle (bukan ditebak).
     * @param  bool  $overridden    true bila admin mengoreksi tipenya manual.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $pgType,
        public readonly TypeCategory $category,
        public readonly ?string $oracleType = null,
        public readonly array $warnings = [],
        public readonly bool $fromMetadata = false,
        public readonly bool $overridden = false,
    ) {
    }

    public function quoted(): string
    {
        return PgIdentifier::quote($this->name);
    }

    public function columnDefinitionSql(): string
    {
        return $this->quoted().' '.$this->pgType;
    }
}
