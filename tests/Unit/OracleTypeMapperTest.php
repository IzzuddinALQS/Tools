<?php

namespace Tests\Unit;

use App\Services\Types\ColumnDefinition;
use App\Services\Types\OracleTypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aturan 3: pemetaan tipe Oracle -> PostgreSQL dengan menjaga presisi.
 */
class OracleTypeMapperTest extends TestCase
{
    private OracleTypeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new OracleTypeMapper;
    }

    #[DataProvider('typeCases')]
    public function test_maps_type(
        string $oracleType,
        ?int $precision,
        ?int $scale,
        ?int $charLength,
        ?int $charUsed,
        string $expectedPgType,
    ): void {
        $mapping = $this->mapper->map(new ColumnDefinition(
            name: 'TEST_COL',
            oracleType: $oracleType,
            dataPrecision: $precision,
            dataScale: $scale,
            charLength: $charLength,
            charUsed: $charUsed,
        ));

        $this->assertSame($expectedPgType, $mapping->pgType);
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public static function typeCases(): array
    {
        return [
            // NUMBER
            'NUMBER(p,s)' => ['NUMBER', 18, 4, null, null, 'numeric(18,4)'],
            'NUMBER(p,0)' => ['NUMBER', 12, 0, null, null, 'numeric(12,0)'],
            'NUMBER tanpa presisi' => ['NUMBER', null, null, null, null, 'numeric'],
            'NUMBER presisi 0' => ['NUMBER', 0, 0, null, null, 'numeric'],
            'NUMBER skala negatif' => ['NUMBER', 5, -2, null, null, 'numeric(5)'],

            // Floating point
            'BINARY_FLOAT' => ['BINARY_FLOAT', null, null, null, null, 'real'],
            'BINARY_DOUBLE' => ['BINARY_DOUBLE', null, null, null, null, 'double precision'],

            // Karakter
            'VARCHAR2(n)' => ['VARCHAR2', null, null, 30, 2, 'varchar(30)'],
            'VARCHAR2 sangat panjang' => ['VARCHAR2', null, null, 5000, 2, 'text'],
            'NVARCHAR2(n)' => ['NVARCHAR2', null, null, 60, 2, 'varchar(60)'],
            'CHAR(n)' => ['CHAR', null, null, 1, 1, 'char(1)'],
            'NCHAR(n)' => ['NCHAR', null, null, 4, 2, 'char(4)'],

            // Teks & biner
            'CLOB' => ['CLOB', null, null, null, null, 'text'],
            'NCLOB' => ['NCLOB', null, null, null, null, 'text'],
            'LONG' => ['LONG', null, null, null, null, 'text'],
            'BLOB' => ['BLOB', null, null, null, null, 'bytea'],
            'RAW' => ['RAW', null, null, null, null, 'bytea'],

            // Waktu
            'DATE harus timestamp, bukan date' => ['DATE', null, null, null, null, 'timestamp(0)'],
            'TIMESTAMP' => ['TIMESTAMP', 6, null, null, null, 'timestamp(6)'],
            'TIMESTAMP(3)' => ['TIMESTAMP', 3, null, null, null, 'timestamp(3)'],
            'TIMESTAMP WITH TIME ZONE' => ['TIMESTAMP WITH TIME ZONE', 6, null, null, null, 'timestamptz'],
            'TIMESTAMP(6) WITH TIME ZONE' => ['TIMESTAMP(6) WITH TIME ZONE', 6, null, null, null, 'timestamptz'],
            'TIMESTAMP WITH LOCAL TIME ZONE' => ['TIMESTAMP WITH LOCAL TIME ZONE', 6, null, null, null, 'timestamptz'],
            'INTERVAL DAY TO SECOND' => ['INTERVAL DAY TO SECOND', null, null, null, null, 'interval'],
            'INTERVAL YEAR TO MONTH' => ['INTERVAL YEAR TO MONTH', null, null, null, null, 'interval'],

            // Fallback
            'ROWID' => ['ROWID', null, null, null, null, 'text'],
            'XMLTYPE' => ['XMLTYPE', null, null, null, null, 'text'],
            'SDO geometry' => ['MDSYS.SDO_GEOMETRY', null, null, null, null, 'text'],
            'JSON' => ['JSON', null, null, null, null, 'text'],
        ];
    }

    public function test_date_never_maps_to_date_type(): void
    {
        $mapping = $this->mapper->map(new ColumnDefinition('ORDER_DATE', 'DATE'));

        $this->assertNotSame('date', $mapping->pgType);
        $this->assertSame('timestamp(0)', $mapping->pgType);
        $this->assertTrue($mapping->hasWarnings());
    }

    public function test_unknown_type_falls_back_to_text_with_warning(): void
    {
        $mapping = $this->mapper->map(new ColumnDefinition('WEIRD_COL', 'SOMETHING_ELSE'));

        $this->assertSame('text', $mapping->pgType);
        $this->assertTrue($mapping->fallback);
        $this->assertNotEmpty($mapping->warnings);
        $this->assertStringContainsString('SOMETHING_ELSE', implode(' ', $mapping->warnings));
    }

    public function test_number_without_precision_warns(): void
    {
        $mapping = $this->mapper->map(new ColumnDefinition('QTY', 'NUMBER'));

        $this->assertTrue($mapping->hasWarnings());
    }

    public function test_binary_float_warns_about_precision(): void
    {
        $mapping = $this->mapper->map(new ColumnDefinition('RATIO', 'BINARY_FLOAT'));

        $this->assertTrue($mapping->hasWarnings());
    }
}
