<?php

namespace Tests\Unit;

use App\Services\Verify\Canonicalizer;
use App\Services\Verify\ContentHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aturan 4 & 5: nilai disalin apa adanya, dan bentuk kanoniknya harus sama
 * antara sisi Oracle (oci8) dan sisi PostgreSQL (pdo_pgsql).
 */
class CanonicalizerTest extends TestCase
{
    /**
     * Bentuk paling penting: Oracle dan PostgreSQL menuliskan angka desimal
     * yang BERBEDA untuk nilai yang sama.
     */
    #[DataProvider('numericPairs')]
    public function test_numeric_is_identical_across_sides(string $oracleValue, string $pgValue): void
    {
        $this->assertSame(
            Canonicalizer::numeric($oracleValue),
            Canonicalizer::numeric($pgValue),
            "Nilai {$oracleValue} dan {$pgValue} harus punya bentuk kanonik sama"
        );
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function numericPairs(): array
    {
        return [
            'skala berbeda' => ['123.45', '123.4500'],
            'nol di belakang' => ['1.5', '1.500000'],
            'bulat vs desimal' => ['100', '100.0000'],
            'negatif' => ['-12.3400', '-12.34'],
            'nol ekspon oracle' => ['1.5E+10', '15000000000'],
            'nol ekspon postgres' => ['15000000000', '1.5e10'],
            'nilai nol' => ['0.0000', '0'],
            'nol negatif' => ['-0.0', '0'],
            'presisi panjang' => ['98765432109876.5432', '98765432109876.5432'],
            'nol di depan' => ['000123.45', '123.450'],
            'sangat kecil' => ['0.0001', '0.00010000'],
            'sangat besar' => ['99999999999999999999', '99999999999999999999.0000'],
        ];
    }

    #[DataProvider('numericCases')]
    public function test_numeric_canonical_form(string $input, string $expected): void
    {
        $this->assertSame($expected, Canonicalizer::numeric($input));
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function numericCases(): array
    {
        return [
            ['123.4500', '123.45'],
            ['100.0000', '100'],
            ['0.0000', '0'],
            ['-0.0000', '0'],
            ['-12.3400', '-12.34'],
            ['1.5E+10', '15000000000'],
            ['1.5e-3', '0.0015'],
            ['000123.450', '123.45'],
            ['12.', '12'],
            ['.5', '0.5'],
            ['.', '0'],
            ['', '0'],
        ];
    }

    public function test_text_is_not_trimmed(): void
    {
        // Aturan 4: tanpa trim. Spasi adalah bagian dari nilainya.
        $this->assertSame('  PADI  ', Canonicalizer::text('  PADI  '));
        $this->assertSame('ABC       ', Canonicalizer::text('ABC       '));
    }

    public function test_bytea_is_base64(): void
    {
        $this->assertSame(base64_encode("\x00\x01\x02"), Canonicalizer::bytea("\x00\x01\x02"));
    }

    public function test_float_is_deterministic_for_same_double(): void
    {
        // Nilai float yang sama harus menghasilkan teks yang sama, apa pun
        // bentuk string aslinya.
        $this->assertSame(
            Canonicalizer::float(0.1),
            Canonicalizer::float('0.1')
        );

        $this->assertSame('1.500000000000000E+00', Canonicalizer::float(1.5));
    }

    public function test_timestamp_keeps_time_component(): void
    {
        // DATE Oracle punya jam; jam tidak boleh hilang (Aturan 3).
        $this->assertSame(
            '2026-03-15T08:15:00.000000',
            Canonicalizer::timestamp('2026-03-15 08:15:00', withOffset: false)
        );
    }

    public function test_timestamptz_is_normalized_to_utc(): void
    {
        $this->assertSame(
            '2026-03-15T08:15:00.000000+00:00',
            Canonicalizer::timestamp('2026-03-15 08:15:00+00:00', withOffset: true)
        );

        $this->assertSame(
            '2026-03-15T08:15:00.000000+00:00',
            Canonicalizer::timestamp('2026-03-15 08:15:00+00', withOffset: true)
        );
    }

    public function test_microseconds_are_preserved(): void
    {
        $this->assertSame(
            '2026-03-15T08:15:00.123456',
            Canonicalizer::timestamp('2026-03-15 08:15:00.123456', withOffset: false)
        );

        $this->assertSame(
            '2026-03-15T08:15:00.123000',
            Canonicalizer::timestamp('2026-03-15 08:15:00.123', withOffset: false)
        );
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(Canonicalizer::canonicalize(null, 'numeric(10,2)'));
        $this->assertNull(Canonicalizer::canonicalize(null, 'text'));
        $this->assertNull(Canonicalizer::canonicalize(null, 'timestamp(0)'));
        $this->assertNull(Canonicalizer::canonicalize(null, 'bytea'));
    }

    public function test_hash_is_order_independent(): void
    {
        $rows = [
            ['1', 'a'],
            ['2', 'b'],
            ['3', 'c'],
        ];

        $forward = new ContentHasher;

        foreach ($rows as $row) {
            $forward->addRow($row);
        }

        $reversed = new ContentHasher;

        foreach (array_reverse($rows) as $row) {
            $reversed->addRow($row);
        }

        $this->assertSame($forward->digest(), $reversed->digest());
        $this->assertSame(3, $forward->count());
    }

    public function test_hash_detects_changed_value(): void
    {
        $a = new ContentHasher;
        $a->addRow(['1', 'a']);
        $a->addRow(['2', 'b']);

        $b = new ContentHasher;
        $b->addRow(['1', 'a']);
        $b->addRow(['2', 'CHANGED']);

        $this->assertNotSame($a->digest(), $b->digest());
    }

    public function test_hash_detects_missing_row(): void
    {
        $a = new ContentHasher;
        $a->addRow(['1', 'a']);
        $a->addRow(['2', 'b']);

        $b = new ContentHasher;
        $b->addRow(['1', 'a']);

        $this->assertNotSame($a->digest(), $b->digest());
    }

    public function test_hash_detects_duplicate_rows_that_xor_would_hide(): void
    {
        // Dua baris identik: XOR saling meniadakan, jadi hanya
        // jumlah baris + SUM yang bisa membedakan dari satu baris.
        $twoDuplicates = new ContentHasher;
        $twoDuplicates->addRow(['x']);
        $twoDuplicates->addRow(['x']);

        $oneRow = new ContentHasher;
        $oneRow->addRow(['x']);

        $this->assertNotSame($twoDuplicates->digest(), $oneRow->digest());
    }

    public function test_row_serialization_is_unambiguous(): void
    {
        // Nilai yang berisi pemisah tidak boleh menabrak nilai berikutnya.
        $this->assertNotSame(
            ContentHasher::serializeRow(['a:b', 'c']),
            ContentHasher::serializeRow(['a', 'b:c'])
        );
    }

    public function test_null_and_empty_string_are_different(): void
    {
        $this->assertNotSame(
            ContentHasher::serializeRow([null]),
            ContentHasher::serializeRow([''])
        );
    }
}
