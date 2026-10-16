<?php

namespace App\Services\Types;

/**
 * Hasil pemetaan satu kolom Oracle -> PostgreSQL.
 */
final class TypeMapping
{
    /**
     * @param  array<int,string>  $warnings  Peringatan yang WAJIB tampil di Preview.
     */
    public function __construct(
        public readonly string $oracleType,
        public readonly string $pgType,
        public readonly TypeCategory $category,
        public readonly bool $fallback = false,
        public readonly array $warnings = [],
    ) {
    }

    public function withOverride(string $pgType, ?string $note = null): self
    {
        $warnings = $this->warnings;

        if ($note !== null) {
            $warnings[] = $note;
        }

        return new self(
            oracleType: $this->oracleType,
            pgType: $pgType,
            category: PgType::categoryOf($pgType),
            fallback: false,
            warnings: $warnings,
        );
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
