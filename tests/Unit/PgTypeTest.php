<?php

namespace Tests\Unit;

use App\Services\Types\PgType;
use App\Services\Types\TypeCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kategori tipe PostgreSQL menentukan aturan kanonikalisasi hash.
 *
 * Wajib mengenali DUA bentuk penulisan tipe:
 *  - bentuk yang disusun pemetaan Oracle -> PostgreSQL
 *  - bentuk yang dikembalikan katalog PostgreSQL (format_type)
 */
class PgTypeTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_category(string $pgType, TypeCategory $expected): void
    {
        $this->assertSame($expected, PgType::categoryOf($pgType), "Tipe: {$pgType}");
    }

    /**
     * @return array<string,array{0:string,1:TypeCategory}>
     */
    public static function cases(): array
    {
        return [
            // Bentuk dari pemetaan Oracle
            'numeric(p,s)' => ['numeric(12,0)', TypeCategory::Numeric],
            'numeric' => ['numeric', TypeCategory::Numeric],
            'double precision' => ['double precision', TypeCategory::Float],
            'real' => ['real', TypeCategory::Float],
            'bytea' => ['bytea', TypeCategory::Bytea],
            'boolean' => ['boolean', TypeCategory::Boolean],
            'timestamp(0)' => ['timestamp(0)', TypeCategory::Timestamp],
            'timestamp(6)' => ['timestamp(6)', TypeCategory::Timestamp],
            'timestamptz' => ['timestamptz', TypeCategory::TimestampTz],
            'varchar(30)' => ['varchar(30)', TypeCategory::Text],
            'char(1)' => ['char(1)', TypeCategory::Text],
            'text' => ['text', TypeCategory::Text],

            // Bentuk dari katalog PostgreSQL (format_type)
            'numeric(12,0) format_type' => ['numeric(12,0)', TypeCategory::Numeric],
            'character varying(30)' => ['character varying(30)', TypeCategory::Text],
            'character(1)' => ['character(1)', TypeCategory::Text],
            'timestamp(0) without time zone' => ['timestamp(0) without time zone', TypeCategory::Timestamp],
            'timestamp(6) without time zone' => ['timestamp(6) without time zone', TypeCategory::Timestamp],
            'timestamp without time zone' => ['timestamp without time zone', TypeCategory::Timestamp],
            'timestamp(6) with time zone' => ['timestamp(6) with time zone', TypeCategory::TimestampTz],
            'timestamptz format_type' => ['timestamp with time zone', TypeCategory::TimestampTz],
            'interval day to second' => ['interval day to second', TypeCategory::Interval],
            'double precision format_type' => ['double precision', TypeCategory::Float],
            'bytea format_type' => ['bytea', TypeCategory::Bytea],
            'text format_type' => ['text', TypeCategory::Text],
        ];
    }

    public function test_kolom_char_dikenali_pada_kedua_bentuk(): void
    {
        $this->assertTrue(PgType::isBlankPadded('char(1)'));
        $this->assertTrue(PgType::isBlankPadded('character(1)'));
        $this->assertFalse(PgType::isBlankPadded('varchar(30)'));
        $this->assertFalse(PgType::isBlankPadded('text'));
        $this->assertFalse(PgType::isBlankPadded('character varying(30)'));
    }
}
