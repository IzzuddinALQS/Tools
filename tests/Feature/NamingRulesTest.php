<?php

namespace Tests\Feature;

use App\Enums\RunStatusEnum;
use App\Services\Source\SourceReaderFactory;
use App\Services\Sync\PreviewService;
use App\Services\Sync\SyncRunner;

/**
 * Aturan 1 & 2: nama harus identik dengan Oracle bila query membaca satu
 * tabel; kalau tidak, user WAJIB memberi nama sendiri.
 */
class NamingRulesTest extends SyncTestCase
{
    public function test_unique_index_menegakkan_satu_tabel_satu_dataset(): void
    {
        $this->dataset();

        // Lapis kedua: database sendiri menolak duplikat, apa pun yang
        // dilakukan layer aplikasi.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->dataset();
    }

    public function test_query_tanpa_schema_memakai_schema_bawaan_sumber(): void
    {
        // Dulu schema bawaan ditulis mati 'APPUSER', sehingga di Oracle asli
        // `FROM PO_HEADERS` mencari APPUSER.PO_HEADERS dan gagal.
        $preview = app(PreviewService::class)->preview(
            'SELECT * FROM PO_HEADERS',
            $this->readerFor($this->mockConnection()),
        );

        $this->assertTrue($preview['identity_mode']);
        $this->assertSame('APPS', $preview['schema']);
        $this->assertSame('PO_HEADERS', $preview['table']);
    }

    public function test_query_join_wajib_memberi_nama_tujuan_sendiri(): void
    {
        $connection = $this->mockConnection();

        $reader = $this->readerFor($connection);

        $this->expectException(\App\Services\Source\Exceptions\SyncException::class);
        $this->expectExceptionMessage('WAJIB memberi nama tabel tujuan sendiri');

        app(PreviewService::class)->preview(
            'SELECT h.PO_NUMBER, l.ITEM_CODE FROM APPS.PO_HEADERS h JOIN APPS.PO_LINES l ON l.PO_HEADER_ID = h.PO_HEADER_ID',
            $reader,
        );
    }

    public function test_query_dengan_alias_wajib_memberi_nama_tujuan_sendiri(): void
    {
        $connection = $this->mockConnection();

        $this->expectException(\App\Services\Source\Exceptions\SyncException::class);
        $this->expectExceptionMessage('WAJIB memberi nama tabel tujuan sendiri');

        app(PreviewService::class)->preview(
            'SELECT PO_NUMBER AS NOMOR_PO FROM APPS.PO_HEADERS',
            $this->readerFor($connection),
        );
    }

    public function test_query_agregasi_wajib_memberi_nama_tujuan_sendiri(): void
    {
        $connection = $this->mockConnection();

        $this->expectException(\App\Services\Source\Exceptions\SyncException::class);

        app(PreviewService::class)->preview(
            'SELECT STATUS_CODE, COUNT(*) AS TOTAL FROM APPS.PO_HEADERS GROUP BY STATUS_CODE',
            $this->readerFor($connection),
        );
    }

    public function test_query_join_dengan_nama_tujuan_sendiri_boleh_dijalankan(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT h.PO_NUMBER AS PO_NUMBER, l.ITEM_CODE AS ITEM_CODE
               FROM APPS.PO_HEADERS h JOIN APPS.PO_LINES l ON l.PO_HEADER_ID = h.PO_HEADER_ID',
            $this->readerFor($connection),
            'PUBLIC_TEST',
            'PO_ITEM_REKAP',
        );

        $this->assertFalse($preview['identity_mode']);
        $this->assertSame('PUBLIC_TEST', $preview['schema']);
        $this->assertSame('PO_ITEM_REKAP', $preview['table']);

        // Tipe default text + peringatan, menunggu konfirmasi admin.
        $this->assertNotEmpty($preview['warnings']);
        $this->assertNotEmpty($preview['rows']);
    }

    public function test_kolom_boleh_dipilih_tetapi_nama_dan_tipe_tetap_identik(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT PO_HEADER_ID, ORDER_DATE, APPROVED_FLAG FROM APPS.PO_HEADERS',
            $this->readerFor($connection),
        );

        $this->assertTrue($preview['identity_mode']);
        $this->assertSame('APPS.PO_HEADERS', $preview['source_table']);
        $this->assertSame(
            ['PO_HEADER_ID', 'ORDER_DATE', 'APPROVED_FLAG'],
            array_column($preview['columns'], 'name'),
        );

        $types = array_combine(
            array_column($preview['columns'], 'name'),
            array_column($preview['columns'], 'pg_type'),
        );

        $this->assertSame('numeric(12,0)', $types['PO_HEADER_ID']);
        $this->assertSame('timestamp(0)', $types['ORDER_DATE']);
        $this->assertSame('char(1)', $types['APPROVED_FLAG']);

        // Semua kolom berasal dari metadata Oracle, bukan tebakan.
        foreach ($preview['columns'] as $column) {
            $this->assertTrue($column['from_metadata']);
        }
    }

    public function test_preview_tidak_menyimpan_apa_pun(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT PO_HEADER_ID, PO_NUMBER FROM APPS.PO_HEADERS',
            $this->readerFor($connection),
        );

        $this->assertFalse($preview['saved']);
        $this->assertSame(20, $preview['limit']);
        $this->assertLessThanOrEqual(20, $preview['row_count_shown']);

        // Tidak ada tabel dibuat di PostgreSQL A.
        $this->assertSame([], $this->tableNames($this->pgA(), 'APPS'));
    }

    public function test_preview_membatasi_baris(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT RECEIPT_HEADER_ID FROM APPS.RECEIPT_HEADERS',
            $this->readerFor($connection),
            null,
            null,
            null,
            2,
        );

        $this->assertSame(2, $preview['row_count_shown']);
    }

    public function test_select_star_mengambil_semua_kolom_metadata(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT * FROM APPS.PO_LINES',
            $this->readerFor($connection),
        );

        $this->assertTrue($preview['identity_mode']);
        $this->assertSame('APPS.PO_LINES', $preview['source_table']);
        $this->assertSame(
            ['PO_LINE_ID', 'PO_HEADER_ID', 'LINE_NUMBER', 'ITEM_CODE', 'ORDERED_QTY', 'UNIT_OF_MEASURE', 'UNIT_PRICE'],
            array_column($preview['columns'], 'name'),
        );
    }

    public function test_ekspresi_di_select_list_tidak_pakai_mode_identitas(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT PO_HEADER_ID, UPPER(PO_NUMBER) FROM APPS.PO_HEADERS',
            $this->readerFor($connection),
            'APPS',
            'PO_HEADERS_UPPER',
        );

        $this->assertFalse($preview['identity_mode']);

        // Tipe tidak boleh ditebak dari isi: default text + peringatan.
        $second = $preview['columns'][1];

        $this->assertSame('text', $second['pg_type']);
        $this->assertFalse($second['from_metadata']);
        $this->assertNotEmpty($second['warnings']);
    }

    public function test_kolom_bisa_dikonfirmasi_manual_oleh_admin(): void
    {
        $connection = $this->mockConnection();

        $preview = app(PreviewService::class)->preview(
            'SELECT PO_HEADER_ID, ORDERED_QTY, UNIT_PRICE FROM APPS.PO_LINES',
            $this->readerFor($connection),
            'APPS',
            'PO_LINES_REKAP',
            [
                'ORDERED_QTY' => 'numeric(18,4)',
                'UNIT_PRICE' => 'numeric(18,4)',
            ],
        );

        $types = array_combine(
            array_column($preview['columns'], 'name'),
            array_column($preview['columns'], 'pg_type'),
        );

        $this->assertSame('numeric(18,4)', $types['ORDERED_QTY']);
        $this->assertSame('numeric(18,4)', $types['UNIT_PRICE']);
        // Kolom yang tidak dikoreksi tetap text.
        $this->assertSame('text', $types['PO_LINE_ID'] ?? 'text');
    }

    public function test_sync_gagal_bila_tabel_tujuan_sudah_dimiliki_dataset_lain(): void
    {
        $this->dataset();

        // Bypass unique index dengan mengubah destination lalu mengembalikannya.
        $second = $this->dataset([
            'destination_schema' => 'PUBLIC_TEST',
            'destination_table' => 'PO_HEADERS',
        ]);

        $second->update(['destination_table' => 'PO_HEADERS']);

        $run = app(SyncRunner::class)->run($second->fresh());

        $this->assertSame(RunStatusEnum::Failed, $run->status);
    }

    private function readerFor(\App\Models\Connection $connection): \App\Services\Source\SourceReader
    {
        return app(SourceReaderFactory::class)->for($connection);
    }
}
