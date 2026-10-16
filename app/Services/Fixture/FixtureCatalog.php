<?php

namespace App\Services\Fixture;

use App\Services\Fixture\Exceptions\FixtureException;
use App\Services\Types\ColumnDefinition;

/**
 * Katalog fixture: tiruan tabel Oracle lengkap dengan metadata kolom dan tipe
 * gaya Oracle, supaya seluruh alur (Preview, Simpan ke A, Copy ke B,
 * verifikasi) bisa diuji tanpa Oracle.
 *
 * Format JSON:
 * {
 *   "set": "armors",
 *   "default_schema": "APPS",
 *   "sysdate": "2026-03-15 09:00:00",
 *   "tables": [
 *     {
 *       "schema": "APPS", "name": "PO_HEADERS",
 *       "columns": [
 *         {"name":"PO_HEADER_ID","oracle_type":"NUMBER",
 *          "data_precision":12,"data_scale":0,"position":1}
 *       ],
 *       "rows": [ {"PO_HEADER_ID":"1001"} ]
 *     }
 *   ]
 * }
 *
 * Kolom biner ditulis sebagai base64 dan diberi "encoding": "base64".
 */
final class FixtureCatalog
{
    /** @var array<string,FixtureCatalog> */
    private static array $overrides = [];

    /**
     * @param  array<int,FixtureTable>  $tables
     */
    private function __construct(
        private readonly string $set,
        private readonly string $defaultSchema,
        private readonly string $sysdate,
        private readonly array $tables,
        private readonly string $source,
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data, string $source = 'array'): self
    {
        if (! isset($data['tables']) || ! is_array($data['tables'])) {
            throw new FixtureException('Fixture tidak valid: key "tables" tidak ditemukan ('.$source.').');
        }

        $defaultSchema = strtoupper((string) ($data['default_schema'] ?? 'APPS'));
        $sysdate = (string) ($data['sysdate'] ?? '2026-01-01 00:00:00');

        $tables = [];

        foreach ($data['tables'] as $index => $raw) {
            $tables[] = FixtureTable::fromArray((array) $raw, $defaultSchema, $index);
        }

        return new self(
            set: (string) ($data['set'] ?? 'custom'),
            defaultSchema: $defaultSchema,
            sysdate: $sysdate,
            tables: $tables,
            source: $source,
        );
    }

    /**
     * Muat fixture berdasarkan nama set, atau path absolut ke file JSON.
     */
    public static function load(string $setOrPath): self
    {
        if (isset(self::$overrides[$setOrPath])) {
            return self::$overrides[$setOrPath];
        }

        $candidates = [];

        if (is_file($setOrPath)) {
            $candidates[] = $setOrPath;
        } else {
            $base = (string) config('oracle-sync.fixtures.path');
            $candidates[] = rtrim($base, '/\\').DIRECTORY_SEPARATOR.$setOrPath.'.json';
        }

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $contents = file_get_contents($candidate);
            $decoded = json_decode((string) $contents, true);

            if (! is_array($decoded)) {
                throw new FixtureException("Fixture JSON tidak valid: {$candidate} (".json_last_error_msg().').');
            }

            return self::fromArray($decoded, $candidate);
        }

        throw new FixtureException(
            "Fixture '{$setOrPath}' tidak ditemukan. Dicari: ".implode(', ', $candidates)
        );
    }

    /**
     * Ganti isi fixture secara in-memory. Dipakai feature test supaya tidak
     * perlu menulis file untuk tiap skenario.
     *
     * @param  array<string,mixed>  $data
     */
    public static function fake(string $set, array $data): self
    {
        return self::$overrides[$set] = self::fromArray($data, 'fake:'.$set);
    }

    public static function clearFakes(): void
    {
        self::$overrides = [];
    }

    public function set(): string
    {
        return $this->set;
    }

    public function defaultSchema(): string
    {
        return $this->defaultSchema;
    }

    /**
     * SYSDATE deterministik supaya hasil test bisa diulang.
     */
    public function sysdate(): string
    {
        return $this->sysdate;
    }

    /**
     * @return array<int,FixtureTable>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @return array<int,string>
     */
    public function tableNames(): array
    {
        return array_map(fn (FixtureTable $table) => $table->qualifiedName(), $this->tables);
    }

    public function find(string $owner, string $table): ?FixtureTable
    {
        $owner = strtoupper($owner);
        $table = strtoupper($table);

        foreach ($this->tables as $fixtureTable) {
            if ($fixtureTable->table() === $table && ($owner === $fixtureTable->schema() || $owner === '')) {
                return $fixtureTable;
            }
        }

        // Tanpa owner yang cocok, cari satu-satunya tabel dengan nama itu.
        $matches = array_values(array_filter(
            $this->tables,
            fn (FixtureTable $fixtureTable) => $fixtureTable->table() === $table,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Metadata kolom, sama bentuknya dengan ALL_TAB_COLUMNS Oracle.
     *
     * @return array<int,ColumnDefinition>|null
     */
    public function columns(string $owner, string $table): ?array
    {
        return $this->find($owner, $table)?->columns();
    }
}
