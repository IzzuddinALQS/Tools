<?php

namespace Tests\Feature;

use App\Services\Fixture\FixtureCatalog;
use App\Services\Pg\PgConnectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithDatabases;
use Tests\TestCase;

abstract class SyncTestCase extends TestCase
{
    use InteractsWithDatabases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTargets();
    }

    protected function tearDown(): void
    {
        FixtureCatalog::clearFakes();

        parent::tearDown();
    }

    /**
     * Terapkan fixture modifikasi pada Connection mock yang sudah dibuat.
     *
     * Dipakai untuk mensimulasikan perubahan data di Oracle: baris ditambah,
     * diubah, atau dihapus.
     */
    protected function applyFixture(string $set, callable $mutator): FixtureCatalog
    {
        $catalog = $this->loadArmorFixture();

        $data = $this->catalogToArray($catalog);

        $mutator($data);

        return $this->fakeFixture($set, $data);
    }

    /**
     * @return array<string,mixed>
     */
    protected function catalogToArray(FixtureCatalog $catalog): array
    {
        $tables = [];

        foreach ($catalog->tables() as $table) {
            $tables[] = [
                'schema' => $table->schema(),
                'name' => $table->table(),
                'columns' => array_map(fn ($column) => [
                    'name' => $column->name,
                    'oracle_type' => $column->oracleType,
                    'data_length' => $column->dataLength,
                    'char_length' => $column->charLength,
                    'char_used' => $column->charUsed,
                    'data_precision' => $column->dataPrecision,
                    'data_scale' => $column->dataScale,
                    'position' => $column->position,
                    //WAJIB dipertahankan: kolom biner ditulis sebagai base64.
                    'encoding' => in_array($column->name, $table->binaryColumns(), true) ? 'base64' : null,
                ], $table->columns()),
                'rows' => $table->rows(),
            ];
        }

        return [
            'set' => $catalog->set(),
            'default_schema' => $catalog->defaultSchema(),
            'sysdate' => $catalog->sysdate(),
            'tables' => $tables,
        ];
    }
}
